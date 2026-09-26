/* Native, process-local Magento GET export cache.
 * Included by pcov_dump.c after its record types and serialization helpers.
 * Every request is still recorded and validated. Entries contain only native
 * bytes owned by PCOV, never request-allocated pointers or PHP application data.
 */
#ifndef PCOV_REQUEST_CACHE_H
#define PCOV_REQUEST_CACHE_H

#include "SAPI.h"
#include "main/php_variables.h"

#define PCOV_REQUEST_CACHE_SLOTS 16
#define PCOV_REQUEST_CACHE_ENTRY_LIMIT (512U * 1024U)
#define PCOV_REQUEST_CACHE_CONTEXT_LIMIT (64U * 1024U)

typedef struct {
	zend_string *context;
	zend_string *record;
	uint64_t expires;
	uint64_t coverage_files;
	uint64_t executable_lines;
} php_pcov_request_cache_entry;

/* This release supports NTS. Never share mutable cache state in a ZTS build. */
#ifndef ZTS
static php_pcov_request_cache_entry php_pcov_request_cache[PCOV_REQUEST_CACHE_SLOTS];
static size_t php_pcov_request_cache_next;
#endif

static void php_pcov_request_cache_clear(void) {
#ifndef ZTS
	size_t i;
	for (i = 0; i < PCOV_REQUEST_CACHE_SLOTS; i++) {
		php_pcov_request_cache_entry *entry = &php_pcov_request_cache[i];
		if (entry->context) zend_string_release_ex(entry->context, 1);
		if (entry->record) zend_string_release_ex(entry->record, 1);
		memset(entry, 0, sizeof(*entry));
	}
	php_pcov_request_cache_next = 0;
#endif
}

static zend_string *php_pcov_request_cache_context(zend_string *path) {
	zval *server, *value;
	zend_string *name;
	smart_str context = {0};
	char directory[MAXPATHLEN], resolved[MAXPATHLEN];
	const char *slash;
	size_t length;
#ifdef ZTS
	(void) path;
	return NULL;
#endif
	/* This caches validated coverage bytes, not an HTTP response or a sample
	 * inferred from a URL. Private/no-store responses are safe: every request
	 * still executes, records and validates its own files and actual hits. */
	if (!PCG(ini.request_magento_cache) || !SG(request_info).request_method ||
	    strcmp(SG(request_info).request_method, "GET") ||
	    !SG(request_info).request_uri || SG(request_info).request_uri[0] != '/' ||
	    strlen(SG(request_info).request_uri) > PCOV_REQUEST_CACHE_CONTEXT_LIMIT ||
	    SG(sapi_headers).http_response_code != 200 ||
	    (PG(last_error_type) & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR))) return NULL;
	zend_is_auto_global_str(ZEND_STRL("_SERVER"));
	server = &PG(http_globals)[TRACK_VARS_SERVER];
	if (Z_TYPE_P(server) != IS_ARRAY) return NULL;
	/* The caller's existing output directory supplies suite isolation. */
	if (!ZSTR_LEN(path) || strlen(ZSTR_VAL(path)) != ZSTR_LEN(path)) return NULL;
	slash = strrchr(ZSTR_VAL(path), '/');
	length = slash ? (size_t) (slash - ZSTR_VAL(path)) : 1;
	if (length >= sizeof(directory)) return NULL;
	if (slash) { memcpy(directory, ZSTR_VAL(path), length); directory[length] = '\0'; }
	else { memcpy(directory, ".", 2); }
	if (!length) memcpy(directory, "/", 2);
	if (!VCWD_REALPATH(directory, resolved)) return NULL;
	smart_str_appends(&context, resolved); smart_str_appendc(&context, '\0');
	smart_str_appends(&context, SG(request_info).request_uri); smart_str_appendc(&context, '\0');
	smart_str_appends(&context, PCG(ini.directory) ? PCG(ini.directory) : ""); smart_str_appendc(&context, '\0');
	smart_str_appends(&context, PCG(ini.exclude) ? PCG(ini.exclude) : ""); smart_str_appendc(&context, '\0');
	if (ZSTR_LEN(context.s) > PCOV_REQUEST_CACHE_CONTEXT_LIMIT) { smart_str_free(&context); return NULL; }
	ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARRVAL_P(server), name, value) {
		if (!name || (strncmp(ZSTR_VAL(name), "HTTP_", 5) &&
		    !zend_string_equals_literal(name, "HTTPS") && !zend_string_equals_literal(name, "SERVER_PORT"))) continue;
		if (Z_TYPE_P(value) != IS_STRING || Z_STRLEN_P(value) > PCOV_REQUEST_CACHE_CONTEXT_LIMIT ||
		    ZSTR_LEN(context.s) + ZSTR_LEN(name) + Z_STRLEN_P(value) + 8 > PCOV_REQUEST_CACHE_CONTEXT_LIMIT) {
			smart_str_free(&context); return NULL;
		}
		php_pcov_dump_append_u32(&context, (uint32_t) ZSTR_LEN(name)); smart_str_append(&context, name);
		php_pcov_dump_append_u32(&context, (uint32_t) Z_STRLEN_P(value)); smart_str_append(&context, Z_STR_P(value));
	} ZEND_HASH_FOREACH_END();
	smart_str_0(&context);
	return context.s;
}

/* Compare native bytes against the CURRENT validated files and sorted hits.
 * No URL-only hit can suppress new coverage or reuse a different filter result.
 * Entries are trusted process-owned records, but bounds are still checked. */
static zend_bool php_pcov_request_cache_matches(zend_string *record,
		php_pcov_manifest_file_t *files, size_t file_count,
		php_pcov_dump_hit_t *hits, size_t hit_count,
		const unsigned char *environment, const unsigned char *manifest) {
	const unsigned char *cursor = (const unsigned char *) ZSTR_VAL(record) + PCOV_RECORD_HEADER_SIZE;
	const unsigned char *end = (const unsigned char *) ZSTR_VAL(record) + ZSTR_LEN(record);
	size_t i, hit = 0;
	uint32_t groups;
#define PCOV_CACHE_NEED(n) do { if ((size_t) (end - cursor) < (size_t) (n)) return 0; } while (0)
#define PCOV_CACHE_BYTES(p, n) do { PCOV_CACHE_NEED(n); if (memcmp(cursor, (p), (n))) return 0; cursor += (n); } while (0)
#define PCOV_CACHE_U32(n) do { PCOV_CACHE_NEED(4); if (php_pcov_dump_read_u32(cursor) != (n)) return 0; cursor += 4; } while (0)
	PCOV_CACHE_BYTES(environment, PHP_PCOV_FINGERPRINT_SIZE);
	PCOV_CACHE_BYTES(manifest, PHP_PCOV_FINGERPRINT_SIZE);
	PCOV_CACHE_U32(PCOV_MANIFEST_REASON_MATCH);
	PCOV_CACHE_U32(file_count);
	for (i = 0; i < file_count; i++) {
		PCOV_CACHE_U32(ZSTR_LEN(files[i].file));
		PCOV_CACHE_BYTES(ZSTR_VAL(files[i].file), ZSTR_LEN(files[i].file));
		PCOV_CACHE_BYTES(files[i].fingerprint, PHP_PCOV_FINGERPRINT_SIZE);
	}
	PCOV_CACHE_NEED(4); groups = php_pcov_dump_read_u32(cursor); cursor += 4;
	for (i = 0; i < groups; i++) {
		size_t next, line;
		if (hit == hit_count) return 0;
		next = hit + 1;
		while (next < hit_count && zend_string_equals(hits[hit].file, hits[next].file)) next++;
		PCOV_CACHE_U32(ZSTR_LEN(hits[hit].file));
		PCOV_CACHE_BYTES(ZSTR_VAL(hits[hit].file), ZSTR_LEN(hits[hit].file));
		PCOV_CACHE_U32(next - hit);
		for (line = hit; line < next; line++) { PCOV_CACHE_U32(hits[line].line); }
		hit = next;
	}
#undef PCOV_CACHE_NEED
#undef PCOV_CACHE_BYTES
#undef PCOV_CACHE_U32
	return cursor == end && hit == hit_count;
}

static zend_string *php_pcov_request_cache_lookup(zend_string *context,
		php_pcov_manifest_file_t *files, size_t file_count,
		php_pcov_dump_hit_t *hits, size_t hit_count,
		const unsigned char *environment, const unsigned char *manifest,
		php_pcov_dump_stats_t *stats) {
#ifndef ZTS
	size_t i;
	uint64_t now = php_pcov_dump_now();
	for (i = 0; i < PCOV_REQUEST_CACHE_SLOTS; i++) {
		php_pcov_request_cache_entry *entry = &php_pcov_request_cache[i];
		if (entry->record && now && now < entry->expires &&
		    zend_string_equals(context, entry->context) &&
		    php_pcov_request_cache_matches(entry->record, files, file_count, hits, hit_count, environment, manifest)) {
			stats->coverage_files = entry->coverage_files;
			stats->executable_lines = entry->executable_lines;
			return zend_string_init(ZSTR_VAL(entry->record), ZSTR_LEN(entry->record), 0);
		}
	}
#else
	(void) context; (void) files; (void) file_count; (void) hits; (void) hit_count;
	(void) environment; (void) manifest; (void) stats;
#endif
	return NULL;
}

static void php_pcov_request_cache_store(zend_string *context, zend_string *record,
		unsigned int ttl, php_pcov_dump_stats_t *stats) {
#ifndef ZTS
	php_pcov_request_cache_entry *entry;
	uint64_t now = php_pcov_dump_now();
	if (!now || ZSTR_LEN(context) + ZSTR_LEN(record) > PCOV_REQUEST_CACHE_ENTRY_LIMIT) return;
	entry = &php_pcov_request_cache[php_pcov_request_cache_next++ % PCOV_REQUEST_CACHE_SLOTS];
	if (entry->context) zend_string_release_ex(entry->context, 1);
	if (entry->record) zend_string_release_ex(entry->record, 1);
	entry->context = zend_string_init(ZSTR_VAL(context), ZSTR_LEN(context), 1);
	entry->record = zend_string_init(ZSTR_VAL(record), ZSTR_LEN(record), 1);
	entry->expires = now + (uint64_t) ttl * 1000000000ULL;
	entry->coverage_files = stats->coverage_files;
	entry->executable_lines = stats->executable_lines;
#else
	(void) context; (void) record; (void) ttl; (void) stats;
#endif
}

#endif
