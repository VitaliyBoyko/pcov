/*
  +----------------------------------------------------------------------+
  | PCOV synchronous coverage export                                     |
  +----------------------------------------------------------------------+
  | Native full and validated manifest records for large codebases.       |
  +----------------------------------------------------------------------+
*/

#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include "php.h"
#include "Zend/zend_smart_str.h"
#include "Zend/zend_exceptions.h"
#include "ext/pcre/php_pcre.h"

#include "php_pcov.h"
#include "pcov_fingerprint.h"

#include <errno.h>
#include <limits.h>
#include <stdint.h>
#include <stdlib.h>
#include <string.h>

#include <time.h>

#ifdef HAVE_PCOV_NATIVE_EXPORT
# include <fcntl.h>
# include <sys/resource.h>
# include <sys/types.h>
# include <unistd.h>
#endif

#define PCOV_FILTER_ALL       0
#define PCOV_FILTER_INCLUDE   1
#define PCOV_FILTER_EXCLUDE   2

#define PCOV_RECORD_HEADER_SIZE 48
#define PCOV_RECORD_HITS 3
#define PCOV_RECORD_FORMAT_VERSION 1
#define PCOV_RECORD_FORMAT_COMPATIBILITY 1
#define PCOV_RECORD_FULL 1
#define PCOV_RECORD_MANIFEST 2
#define PCOV_RECORD_MAX_FILE_SIZE (512U * 1024U * 1024U)
#define PCOV_RECORD_MAX_FILES 1000000U
#define PCOV_RECORD_MAX_LINES 100000000U
#define PCOV_RECORD_MAX_PATH (1024U * 1024U)

#define PCOV_MANIFEST_REASON_MATCH 0U
#define PCOV_MANIFEST_REASON_OPEN 1U
#define PCOV_MANIFEST_REASON_CORRUPT 2U
#define PCOV_MANIFEST_REASON_ENVIRONMENT 3U
#define PCOV_MANIFEST_REASON_COMPATIBILITY 4U
#define PCOV_MANIFEST_REASON_NEW_FILE 5U
#define PCOV_MANIFEST_REASON_MODIFIED_FILE 6U
#define PCOV_MANIFEST_REASON_FINGERPRINT 7U
#define PCOV_MANIFEST_REASON_UNKNOWN_HIT 8U
#define PCOV_MANIFEST_REASON_EXPLICIT_FULL 9U
#define PCOV_DUMP_PHASE_NONE       0
#define PCOV_DUMP_PHASE_COLLECT    1
#define PCOV_DUMP_PHASE_SERIALIZE  2
#define PCOV_DUMP_PHASE_OPEN       3
#define PCOV_DUMP_PHASE_WRITE      4
#define PCOV_DUMP_PHASE_CLOSE      5
#define PCOV_DUMP_PHASE_RENAME     6

static const char *php_pcov_dump_phase_name(int phase) { /* {{{ */
	switch (phase) {
		case PCOV_DUMP_PHASE_COLLECT:
			return "collection";
		case PCOV_DUMP_PHASE_SERIALIZE:
			return "serialization";
		case PCOV_DUMP_PHASE_OPEN:
			return "temporary-file open";
		case PCOV_DUMP_PHASE_WRITE:
			return "write";
		case PCOV_DUMP_PHASE_CLOSE:
			return "close";
		case PCOV_DUMP_PHASE_RENAME:
			return "rename";
		default:
			return "export";
	}
} /* }}} */

#ifdef HAVE_PCOV_NATIVE_EXPORT

#ifndef O_CLOEXEC
# define O_CLOEXEC 0
#endif

typedef struct _php_pcov_dump_hit_t {
	zend_string *file;
	uint32_t line;
} php_pcov_dump_hit_t;

typedef struct _php_pcov_manifest_file_t {
	zend_string *file;
	unsigned char fingerprint[PHP_PCOV_FINGERPRINT_SIZE];
	uint32_t status;
} php_pcov_manifest_file_t;

typedef struct _php_pcov_manifest_view_t {
	unsigned char *data;
	size_t size;
	const unsigned char *payload;
	size_t payload_size;
	unsigned char environment_id[PHP_PCOV_FINGERPRINT_SIZE];
	unsigned char manifest_id[PHP_PCOV_FINGERPRINT_SIZE];
	uint32_t file_count;
} php_pcov_manifest_view_t;

PHP_PCOV_API uint64_t php_pcov_dump_now(void) { /* {{{ */
	struct timespec now;

	if (clock_gettime(CLOCK_MONOTONIC, &now) != 0) {
		return 0;
	}

	return ((uint64_t) now.tv_sec * 1000000000ULL) + (uint64_t) now.tv_nsec;
} /* }}} */

PHP_PCOV_API zend_bool php_pcov_dump_benchmark_enabled(void) { /* {{{ */
	const char *enabled = getenv("PCOV_DUMP_BENCH_STATS");

	return enabled && strcmp(enabled, "1") == 0;
} /* }}} */

static int64_t php_pcov_dump_timeval_us(struct timeval *time) { /* {{{ */
	return ((int64_t) time->tv_sec * 1000000) + (int64_t) time->tv_usec;
} /* }}} */

static void php_pcov_dump_usage_delta(
		php_pcov_dump_stats_t *stats,
		struct rusage *before,
		struct rusage *after) { /* {{{ */
	stats->user_us =
		php_pcov_dump_timeval_us(&after->ru_utime) -
		php_pcov_dump_timeval_us(&before->ru_utime);
	stats->system_us =
		php_pcov_dump_timeval_us(&after->ru_stime) -
		php_pcov_dump_timeval_us(&before->ru_stime);
	stats->minor_faults = after->ru_minflt - before->ru_minflt;
	stats->major_faults = after->ru_majflt - before->ru_majflt;
	stats->max_rss_kb = after->ru_maxrss;
} /* }}} */

static void php_pcov_dump_append_u32(smart_str *buffer, uint32_t value) { /* {{{ */
	char bytes[4];

	bytes[0] = (char) ((value >> 24) & 0xff);
	bytes[1] = (char) ((value >> 16) & 0xff);
	bytes[2] = (char) ((value >> 8) & 0xff);
	bytes[3] = (char) (value & 0xff);

	smart_str_appendl(buffer, bytes, sizeof(bytes));
} /* }}} */

static void php_pcov_dump_append_u64(smart_str *buffer, uint64_t value) { /* {{{ */
	php_pcov_dump_append_u32(buffer, (uint32_t) (value >> 32));
	php_pcov_dump_append_u32(buffer, (uint32_t) value);
} /* }}} */

static uint32_t php_pcov_dump_checksum(const char *data, size_t length) { /* {{{ */
	uint32_t checksum = UINT32_MAX;
	size_t index;

	for (index = 0; index < length; index++) {
		uint32_t byte = (uint8_t) data[index];
		int bit;

		checksum ^= byte;
		for (bit = 0; bit < 8; bit++) {
			uint32_t mask = (uint32_t) -(int32_t) (checksum & 1U);
			checksum = (checksum >> 1) ^ (0xedb88320U & mask);
		}
	}

	return ~checksum;
} /* }}} */

static uint32_t php_pcov_dump_read_u32(const unsigned char *bytes) { /* {{{ */
	return ((uint32_t) bytes[0] << 24) |
		((uint32_t) bytes[1] << 16) |
		((uint32_t) bytes[2] << 8) |
		(uint32_t) bytes[3];
} /* }}} */

static uint64_t php_pcov_dump_read_u64(const unsigned char *bytes) { /* {{{ */
	return ((uint64_t) php_pcov_dump_read_u32(bytes) << 32) |
		(uint64_t) php_pcov_dump_read_u32(bytes + 4);
} /* }}} */

static zend_bool php_pcov_dump_hex_id(
		zend_string *hex,
		unsigned char result[PHP_PCOV_FINGERPRINT_SIZE]) { /* {{{ */
	size_t index;

	if (!hex || ZSTR_LEN(hex) != PHP_PCOV_FINGERPRINT_SIZE * 2) {
		return 0;
	}
	for (index = 0; index < PHP_PCOV_FINGERPRINT_SIZE; index++) {
		unsigned char high = (unsigned char) ZSTR_VAL(hex)[index * 2];
		unsigned char low = (unsigned char) ZSTR_VAL(hex)[index * 2 + 1];

		high = high >= '0' && high <= '9' ? high - '0' :
			high >= 'a' && high <= 'f' ? high - 'a' + 10 :
			high >= 'A' && high <= 'F' ? high - 'A' + 10 : 255;
		low = low >= '0' && low <= '9' ? low - '0' :
			low >= 'a' && low <= 'f' ? low - 'a' + 10 :
			low >= 'A' && low <= 'F' ? low - 'A' + 10 : 255;
		if (high > 15 || low > 15) {
			return 0;
		}
		result[index] = (unsigned char) ((high << 4) | low);
	}
	return 1;
} /* }}} */

static zend_string *php_pcov_dump_id_hex(
		const unsigned char id[PHP_PCOV_FINGERPRINT_SIZE]) { /* {{{ */
	static const char digits[] = "0123456789abcdef";
	zend_string *result = zend_string_alloc(PHP_PCOV_FINGERPRINT_SIZE * 2, 0);
	size_t index;

	for (index = 0; index < PHP_PCOV_FINGERPRINT_SIZE; index++) {
		ZSTR_VAL(result)[index * 2] = digits[id[index] >> 4];
		ZSTR_VAL(result)[index * 2 + 1] = digits[id[index] & 15];
	}
	ZSTR_VAL(result)[PHP_PCOV_FINGERPRINT_SIZE * 2] = '\0';
	return result;
} /* }}} */

static const char *php_pcov_manifest_reason_name(uint32_t reason) { /* {{{ */
	switch (reason) {
		case PCOV_MANIFEST_REASON_MATCH: return "match";
		case PCOV_MANIFEST_REASON_OPEN: return "manifest-open";
		case PCOV_MANIFEST_REASON_CORRUPT: return "manifest-corrupt";
		case PCOV_MANIFEST_REASON_ENVIRONMENT: return "environment-identity";
		case PCOV_MANIFEST_REASON_COMPATIBILITY: return "runtime-compatibility";
		case PCOV_MANIFEST_REASON_NEW_FILE: return "new-loaded-file";
		case PCOV_MANIFEST_REASON_MODIFIED_FILE: return "modified-loaded-file";
		case PCOV_MANIFEST_REASON_FINGERPRINT: return "fingerprint-unavailable";
		case PCOV_MANIFEST_REASON_UNKNOWN_HIT: return "hit-not-in-manifest";
		case PCOV_MANIFEST_REASON_EXPLICIT_FULL: return "explicit-full";
		default: return "unknown";
	}
} /* }}} */

static int php_pcov_dump_hit_compare(const void *left, const void *right) { /* {{{ */
	const php_pcov_dump_hit_t *first = (const php_pcov_dump_hit_t *) left;
	const php_pcov_dump_hit_t *second = (const php_pcov_dump_hit_t *) right;
	size_t shared = ZSTR_LEN(first->file) < ZSTR_LEN(second->file) ?
		ZSTR_LEN(first->file) : ZSTR_LEN(second->file);
	int compared = memcmp(ZSTR_VAL(first->file), ZSTR_VAL(second->file), shared);

	if (compared) {
		return compared;
	}
	if (ZSTR_LEN(first->file) < ZSTR_LEN(second->file)) {
		return -1;
	}
	if (ZSTR_LEN(first->file) > ZSTR_LEN(second->file)) {
		return 1;
	}
	if (first->line < second->line) {
		return -1;
	}
	if (first->line > second->line) {
		return 1;
	}

	return 0;
} /* }}} */

static int php_pcov_manifest_file_compare(const void *left, const void *right) { /* {{{ */
	const php_pcov_manifest_file_t *first = left;
	const php_pcov_manifest_file_t *second = right;
	size_t shared = ZSTR_LEN(first->file) < ZSTR_LEN(second->file) ?
		ZSTR_LEN(first->file) : ZSTR_LEN(second->file);
	int compared = memcmp(ZSTR_VAL(first->file), ZSTR_VAL(second->file), shared);

	if (compared) {
		return compared;
	}
	return ZSTR_LEN(first->file) < ZSTR_LEN(second->file) ? -1 :
		ZSTR_LEN(first->file) > ZSTR_LEN(second->file) ? 1 : 0;
} /* }}} */

static void php_pcov_dump_prepare_hit_filter(
		zend_long type, zval *filter, HashTable *selected) { /* {{{ */
	zval *candidate;
	uint32_t capacity = filter ? zend_hash_num_elements(Z_ARRVAL_P(filter)) : 0;

	zend_hash_init(selected, capacity, NULL, NULL, 0);
	if (type == PCOV_FILTER_ALL || !filter) {
		return;
	}

	ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(filter), candidate) {
		if (Z_TYPE_P(candidate) == IS_STRING) {
			zval present;
			ZVAL_TRUE(&present);
			zend_hash_update(selected, Z_STR_P(candidate), &present);
		}
	} ZEND_HASH_FOREACH_END();
} /* }}} */

static zend_bool php_pcov_dump_file_selected(
		zend_string *file, zend_long type, HashTable *selected) { /* {{{ */
	zend_bool matched;

	if (type == PCOV_FILTER_ALL) {
		return 1;
	}
	matched = zend_hash_exists(selected, file);
	return type == PCOV_FILTER_INCLUDE ? matched : !matched;
} /* }}} */

static zend_bool php_pcov_dump_hit_selected(
		php_coverage_t *coverage, zend_long type, HashTable *selected) { /* {{{ */
	zend_bool matched;

	if (type == PCOV_FILTER_ALL) {
		return 1;
	}
	matched = zend_hash_exists(selected, coverage->file);

	return type == PCOV_FILTER_INCLUDE ? matched : !matched;
} /* }}} */

static zend_bool php_pcov_manifest_collect_files(
		zend_long type,
		zval *filter,
		php_pcov_manifest_file_t **result,
		size_t *result_count,
		php_pcov_dump_stats_t *stats,
		uint32_t *reason,
		int *error) { /* {{{ */
	php_pcov_manifest_file_t *files = NULL;
	HashTable selected;
	zend_string *file;
	size_t count = 0;
	size_t position = 0;
	uint64_t started = php_pcov_dump_now();

	php_pcov_dump_prepare_hit_filter(type, filter, &selected);
	ZEND_HASH_FOREACH_STR_KEY(&PCG(files), file) {
		if (file && php_pcov_dump_file_selected(file, type, &selected)) {
			count++;
		}
	} ZEND_HASH_FOREACH_END();

	if (count > SIZE_MAX / sizeof(*files)) {
		zend_hash_destroy(&selected);
		*error = EOVERFLOW;
		return 0;
	}
	if (count) {
		files = safe_emalloc(count, sizeof(*files), 0);
		ZEND_HASH_FOREACH_STR_KEY(&PCG(files), file) {
			if (!file || !php_pcov_dump_file_selected(file, type, &selected)) {
				continue;
			}
			files[position].file = file;
			files[position].status = 0;
			memset(files[position].fingerprint, 0,
				PHP_PCOV_FINGERPRINT_SIZE);
			position++;
		} ZEND_HASH_FOREACH_END();
		qsort(files, count, sizeof(*files), php_pcov_manifest_file_compare);
	}
	zend_hash_destroy(&selected);

	for (position = 0; position < count; position++) {
		int fingerprint_error = 0;
		unsigned char current[PHP_PCOV_FINGERPRINT_SIZE];
		zval *compiled = zend_hash_find(&PCG(fingerprints), files[position].file);
		php_pcov_fingerprint_metrics_t *metrics =
			php_pcov_dump_benchmark_enabled() ?
				&stats->fingerprint_metrics : NULL;
		zend_bool reused = 0;

		if (!compiled || Z_TYPE_P(compiled) != IS_STRING ||
		    Z_STRLEN_P(compiled) != PHP_PCOV_FINGERPRINT_SIZE +
				sizeof(php_pcov_fingerprint_identity_t)) {
			files[position].status = ENODATA;
			if (*reason == PCOV_MANIFEST_REASON_MATCH) {
				*reason = PCOV_MANIFEST_REASON_FINGERPRINT;
			}
			continue;
		}
		memcpy(files[position].fingerprint, Z_STRVAL_P(compiled),
			PHP_PCOV_FINGERPRINT_SIZE);
		if (PCG(ini.large_codebase)) {
			php_pcov_fingerprint_identity_t identity;

			memcpy(&identity,
				Z_STRVAL_P(compiled) + PHP_PCOV_FINGERPRINT_SIZE,
				sizeof(identity));
			reused = php_pcov_fingerprint_identity_matches(
				files[position].file, &identity,
				&fingerprint_error, metrics);
			if (reused) {
				memcpy(current, files[position].fingerprint,
					PHP_PCOV_FINGERPRINT_SIZE);
			}
		}
		if (!reused && !php_pcov_fingerprint_file(
				files[position].file,
				current,
				&fingerprint_error,
				metrics, NULL)) {
			files[position].status = (uint32_t)
				(fingerprint_error > 0 ? fingerprint_error : EIO);
			if (*reason == PCOV_MANIFEST_REASON_MATCH) {
				*reason = PCOV_MANIFEST_REASON_FINGERPRINT;
			}
		} else if (memcmp(current, files[position].fingerprint,
				PHP_PCOV_FINGERPRINT_SIZE) != 0) {
			files[position].status = EAGAIN;
			if (*reason == PCOV_MANIFEST_REASON_MATCH) {
				*reason = PCOV_MANIFEST_REASON_FINGERPRINT;
			}
		}
	}
	stats->fingerprint_ns = php_pcov_dump_now() - started;
	stats->validation_files = count;
	stats->compile_fingerprint_ns = PCG(compile_fingerprint_ns);
	stats->compile_fingerprint_files = PCG(compile_fingerprint_files);
	stats->compile_fingerprint_metrics = PCG(compile_fingerprint_metrics);
	*result = files;
	*result_count = count;
	return 1;
} /* }}} */

static zend_bool php_pcov_manifest_bounds(
		const unsigned char *cursor,
		const unsigned char *end,
		size_t needed) { /* {{{ */
	return cursor <= end && needed <= (size_t) (end - cursor);
} /* }}} */

static void php_pcov_manifest_view_destroy(php_pcov_manifest_view_t *view) { /* {{{ */
	if (view->data) {
		efree(view->data);
	}
	memset(view, 0, sizeof(*view));
} /* }}} */

static zend_bool php_pcov_manifest_load(
		zend_string *path,
		php_pcov_manifest_view_t *view,
		uint32_t *reason,
		int *error,
		php_pcov_dump_stats_t *stats,
		zend_bool profile) { /* {{{ */
	struct stat status;
	const unsigned char *cursor;
	const unsigned char *end;
	const unsigned char *previous = NULL;
	uint32_t previous_length = 0;
	uint32_t index;
	int descriptor;
	size_t offset = 0;
	uint64_t payload_length;
	uint64_t started = 0;

	memset(view, 0, sizeof(*view));
	if (!path || !ZSTR_LEN(path) ||
	    strlen(ZSTR_VAL(path)) != ZSTR_LEN(path)) {
		*reason = PCOV_MANIFEST_REASON_OPEN;
		*error = EINVAL;
		return 0;
	}
	if (profile) {
		stats->manifest_open_calls++;
		started = php_pcov_dump_now();
	}
	descriptor = open(ZSTR_VAL(path), O_RDONLY | O_CLOEXEC);
	if (profile) stats->manifest_open_ns += php_pcov_dump_now() - started;
	if (descriptor < 0) {
		*reason = PCOV_MANIFEST_REASON_OPEN;
		*error = errno;
		return 0;
	}
	if (profile) {
		stats->manifest_stat_calls++;
		started = php_pcov_dump_now();
	}
	if (fstat(descriptor, &status) != 0 || !S_ISREG(status.st_mode) ||
	    status.st_size < PCOV_RECORD_HEADER_SIZE ||
	    (uint64_t) status.st_size > PCOV_RECORD_MAX_FILE_SIZE) {
		if (profile) stats->manifest_stat_ns += php_pcov_dump_now() - started;
		*reason = PCOV_MANIFEST_REASON_CORRUPT;
		*error = errno ? errno : EINVAL;
		close(descriptor);
		return 0;
	}
	if (profile) stats->manifest_stat_ns += php_pcov_dump_now() - started;

	view->size = (size_t) status.st_size;
	if (profile) started = php_pcov_dump_now();
	view->data = safe_emalloc(view->size, 1, 0);
	if (profile) {
		stats->manifest_allocation_ns += php_pcov_dump_now() - started;
		stats->manifest_allocations++;
		stats->manifest_allocated_bytes += view->size;
	}
	while (offset < view->size) {
		if (profile) {
			stats->manifest_read_calls++;
			started = php_pcov_dump_now();
		}
		ssize_t received = read(
			descriptor, view->data + offset, view->size - offset);
		if (profile) stats->manifest_read_ns += php_pcov_dump_now() - started;
		if (received < 0) {
			if (errno == EINTR) {
				continue;
			}
			*reason = PCOV_MANIFEST_REASON_OPEN;
			*error = errno;
			close(descriptor);
			php_pcov_manifest_view_destroy(view);
			return 0;
		}
		if (received == 0) {
			*reason = PCOV_MANIFEST_REASON_CORRUPT;
			*error = EIO;
			close(descriptor);
			php_pcov_manifest_view_destroy(view);
			return 0;
		}
		if (profile) stats->manifest_bytes_read += (uint64_t) received;
		offset += (size_t) received;
	}
	if (profile) started = php_pcov_dump_now();
	if (close(descriptor) != 0) {
		if (profile) stats->manifest_close_ns += php_pcov_dump_now() - started;
		*reason = PCOV_MANIFEST_REASON_OPEN;
		*error = errno;
		php_pcov_manifest_view_destroy(view);
		return 0;
	}
	if (profile) stats->manifest_close_ns += php_pcov_dump_now() - started;

	if (profile) started = php_pcov_dump_now();
	if (memcmp(view->data, "PCOVREC\0", 8) != 0 ||
	    php_pcov_dump_read_u32(view->data + 8) !=
			PCOV_RECORD_FORMAT_VERSION ||
	    php_pcov_dump_read_u32(view->data + 12) !=
			PCOV_RECORD_HEADER_SIZE ||
	    php_pcov_dump_read_u32(view->data + 16) !=
			PCOV_RECORD_MANIFEST ||
	    php_pcov_dump_read_u32(view->data + 20) != 0 ||
	    php_pcov_dump_read_u32(view->data + 44) != 0) {
		*reason = PCOV_MANIFEST_REASON_CORRUPT;
		*error = EINVAL;
		php_pcov_manifest_view_destroy(view);
		if (profile) stats->manifest_header_ns += php_pcov_dump_now() - started;
		return 0;
	}
	if (php_pcov_dump_read_u32(view->data + 36) != PHP_VERSION_ID ||
	    php_pcov_dump_read_u32(view->data + 40) !=
			PCOV_RECORD_FORMAT_COMPATIBILITY) {
		*reason = PCOV_MANIFEST_REASON_COMPATIBILITY;
		*error = EINVAL;
		php_pcov_manifest_view_destroy(view);
		if (profile) stats->manifest_header_ns += php_pcov_dump_now() - started;
		return 0;
	}
	payload_length = php_pcov_dump_read_u64(view->data + 24);
	if (payload_length != view->size - PCOV_RECORD_HEADER_SIZE) {
		*reason = PCOV_MANIFEST_REASON_CORRUPT;
		*error = EINVAL;
		php_pcov_manifest_view_destroy(view);
		if (profile) stats->manifest_header_ns += php_pcov_dump_now() - started;
		return 0;
	}
	view->payload = view->data + PCOV_RECORD_HEADER_SIZE;
	view->payload_size = (size_t) payload_length;
	if (profile) {
		stats->manifest_header_ns += php_pcov_dump_now() - started;
		stats->manifest_checksum_calls++;
		started = php_pcov_dump_now();
	}
	if (php_pcov_dump_checksum(
			(const char *) view->payload, view->payload_size) !=
		php_pcov_dump_read_u32(view->data + 32)) {
		*reason = PCOV_MANIFEST_REASON_CORRUPT;
		*error = EINVAL;
		php_pcov_manifest_view_destroy(view);
		if (profile) stats->manifest_checksum_ns += php_pcov_dump_now() - started;
		return 0;
	}
	if (profile) {
		stats->manifest_checksum_ns += php_pcov_dump_now() - started;
		started = php_pcov_dump_now();
	}

	cursor = view->payload;
	end = cursor + view->payload_size;
	if (!php_pcov_manifest_bounds(cursor, end, 72)) {
		goto corrupt;
	}
	memcpy(view->environment_id, cursor, PHP_PCOV_FINGERPRINT_SIZE);
	cursor += PHP_PCOV_FINGERPRINT_SIZE;
	memcpy(view->manifest_id, cursor, PHP_PCOV_FINGERPRINT_SIZE);
	cursor += PHP_PCOV_FINGERPRINT_SIZE;
	if (php_pcov_dump_read_u32(cursor) != PCOV_MANIFEST_REASON_MATCH) {
		goto corrupt;
	}
	cursor += 4;
	view->file_count = php_pcov_dump_read_u32(cursor);
	cursor += 4;
	if (view->file_count > PCOV_RECORD_MAX_FILES) {
		goto corrupt;
	}

	for (index = 0; index < view->file_count; index++) {
		uint32_t length;
		uint32_t lines;
		uint32_t line_index;
		uint32_t previous_line = 0;

		if (!php_pcov_manifest_bounds(cursor, end, 4)) goto corrupt;
		length = php_pcov_dump_read_u32(cursor); cursor += 4;
		if (!length || length > PCOV_RECORD_MAX_PATH ||
		    !php_pcov_manifest_bounds(cursor, end, (size_t) length + 36)) {
			goto corrupt;
		}
		if (previous) {
			size_t shared = previous_length < length ? previous_length : length;
			int compared = memcmp(previous, cursor, shared);
			if (compared > 0 || (compared == 0 && previous_length >= length)) {
				goto corrupt;
			}
		}
		previous = cursor;
		previous_length = length;
		cursor += length + PHP_PCOV_FINGERPRINT_SIZE;
		lines = php_pcov_dump_read_u32(cursor); cursor += 4;
		if (lines > PCOV_RECORD_MAX_LINES ||
		    !php_pcov_manifest_bounds(cursor, end, (size_t) lines * 4)) {
			goto corrupt;
		}
		for (line_index = 0; line_index < lines; line_index++) {
			uint32_t line = php_pcov_dump_read_u32(cursor); cursor += 4;
			if (!line || (line_index && line <= previous_line)) goto corrupt;
			previous_line = line;
		}
		if (profile) {
			stats->manifest_records_validated++;
			stats->manifest_lines_validated += lines;
		}
	}
	if (cursor != end) goto corrupt;
	if (profile) stats->manifest_record_ns += php_pcov_dump_now() - started;
	return 1;

corrupt:
	if (profile) stats->manifest_record_ns += php_pcov_dump_now() - started;
	*reason = PCOV_MANIFEST_REASON_CORRUPT;
	*error = EINVAL;
	php_pcov_manifest_view_destroy(view);
	return 0;
} /* }}} */

static zend_bool php_pcov_manifest_validate(
		php_pcov_manifest_view_t *view,
		const unsigned char environment_id[PHP_PCOV_FINGERPRINT_SIZE],
		php_pcov_manifest_file_t *files,
		size_t file_count,
		uint32_t *reason) { /* {{{ */
	const unsigned char *cursor = view->payload + 72;
	const unsigned char *end = view->payload + view->payload_size;
	size_t wanted = 0;
	uint32_t index;

	if (memcmp(view->environment_id, environment_id,
			PHP_PCOV_FINGERPRINT_SIZE) != 0) {
		*reason = PCOV_MANIFEST_REASON_ENVIRONMENT;
		return 0;
	}
	for (wanted = 0; wanted < file_count; wanted++) {
		if (files[wanted].status) {
			*reason = PCOV_MANIFEST_REASON_FINGERPRINT;
			return 0;
		}
	}

	wanted = 0;
	for (index = 0; index < view->file_count && wanted < file_count; index++) {
		uint32_t length = php_pcov_dump_read_u32(cursor); cursor += 4;
		const unsigned char *name = cursor;
		const unsigned char *fingerprint;
		uint32_t lines;
		size_t shared;
		int compared;

		cursor += length;
		fingerprint = cursor;
		cursor += PHP_PCOV_FINGERPRINT_SIZE;
		lines = php_pcov_dump_read_u32(cursor); cursor += 4 + ((size_t) lines * 4);
		shared = length < ZSTR_LEN(files[wanted].file) ?
			length : ZSTR_LEN(files[wanted].file);
		compared = memcmp(name, ZSTR_VAL(files[wanted].file), shared);
		if (!compared) {
			compared = length < ZSTR_LEN(files[wanted].file) ? -1 :
				length > ZSTR_LEN(files[wanted].file) ? 1 : 0;
		}
		if (compared < 0) {
			continue;
		}
		if (compared > 0) {
			*reason = PCOV_MANIFEST_REASON_NEW_FILE;
			return 0;
		}
		if (memcmp(fingerprint, files[wanted].fingerprint,
				PHP_PCOV_FINGERPRINT_SIZE) != 0) {
			*reason = PCOV_MANIFEST_REASON_MODIFIED_FILE;
			return 0;
		}
		wanted++;
	}
	if (wanted != file_count || cursor > end) {
		*reason = PCOV_MANIFEST_REASON_NEW_FILE;
		return 0;
	}
	return 1;
} /* }}} */

static zend_bool php_pcov_manifest_validate_hits(
		php_pcov_manifest_view_t *view,
		php_pcov_dump_hit_t *hits,
		size_t hit_count,
		php_pcov_dump_stats_t *stats,
		zend_bool profile) { /* {{{ */
	const unsigned char *cursor = view->payload + 72;
	size_t hit = 0;
	uint32_t file_index;
	uint64_t started = 0;
	uint64_t file_started = 0;

	if (hit_count) {
		if (profile) started = php_pcov_dump_now();
		if (profile) {
			stats->validated_sort_calls++;
			stats->validated_sort_entries += hit_count;
		}
		qsort(hits, hit_count, sizeof(*hits), php_pcov_dump_hit_compare);
		if (profile) {
			size_t index;
			stats->manifest_hit_sort_ns += php_pcov_dump_now() - started;
			for (index = 1; index < hit_count; index++) {
				if (!php_pcov_dump_hit_compare(&hits[index - 1], &hits[index])) {
					stats->manifest_hit_duplicates++;
				}
			}
		}
	}
	if (profile && hit_count) file_started = php_pcov_dump_now();
	for (file_index = 0; file_index < view->file_count && hit < hit_count; file_index++) {
		uint32_t length = php_pcov_dump_read_u32(cursor); cursor += 4;
		const unsigned char *name = cursor;
		uint32_t line_count;
		const unsigned char *line_cursor;
		size_t shared;
		int compared;

		cursor += length + PHP_PCOV_FINGERPRINT_SIZE;
		line_count = php_pcov_dump_read_u32(cursor); cursor += 4;
		line_cursor = cursor;
		cursor += (size_t) line_count * 4;
		shared = length < ZSTR_LEN(hits[hit].file) ?
			length : ZSTR_LEN(hits[hit].file);
		compared = memcmp(name, ZSTR_VAL(hits[hit].file), shared);
		if (!compared) {
			compared = length < ZSTR_LEN(hits[hit].file) ? -1 :
				length > ZSTR_LEN(hits[hit].file) ? 1 : 0;
		}
		if (profile) {
			stats->manifest_hit_files_looked_up++;
		}
		if (compared < 0) continue;
		if (compared > 0) {
			if (profile) {
				stats->manifest_hit_file_lookup_ns +=
					php_pcov_dump_now() - file_started;
				stats->manifest_hit_unknown_files++;
			}
			return 0;
		}

		if (profile) {
			stats->manifest_hit_file_lookup_ns +=
				php_pcov_dump_now() - file_started;
			started = php_pcov_dump_now();
		}
		while (hit < hit_count &&
		       ZSTR_LEN(hits[hit].file) == length &&
		       memcmp(ZSTR_VAL(hits[hit].file), name, length) == 0) {
			uint32_t line_index;
			zend_bool found = 0;
			if (profile) {
				stats->manifest_hit_entries_validated++;
				stats->manifest_hit_search_restarts++;
			}
			for (line_index = 0; line_index < line_count; line_index++) {
				uint32_t line = php_pcov_dump_read_u32(line_cursor + line_index * 4);
				if (profile) {
					stats->manifest_hit_line_comparisons++;
					stats->manifest_hit_line_records_decoded++;
				}
				if (line == hits[hit].line) {
					found = 1;
					break;
				}
				if (line > hits[hit].line) break;
			}
			if (profile && found) stats->manifest_hit_matches++;
			if (!found) {
				if (profile) {
					stats->manifest_hit_unknown_lines++;
					stats->manifest_hit_line_lookup_ns +=
						php_pcov_dump_now() - started;
				}
				return 0;
			}
			hit++;
		}
		if (profile) {
			stats->manifest_hit_line_lookup_ns += php_pcov_dump_now() - started;
			file_started = php_pcov_dump_now();
		}
	}
	if (profile && hit != hit_count) {
		stats->manifest_hit_file_lookup_ns += php_pcov_dump_now() - file_started;
		stats->manifest_hit_unknown_files++;
	}
	return hit == hit_count;
} /* }}} */

static zend_bool php_pcov_dump_collect_hits(
		zend_long type,
		zval *filter,
		php_pcov_dump_hit_t **result,
		size_t *result_count,
		php_pcov_dump_stats_t *stats,
		zend_bool benchmark,
		int *error) { /* {{{ */
	php_coverage_t *coverage = PCG(start);
	php_pcov_dump_hit_t *hits = NULL;
	HashTable selected;
	size_t count = 0;
	size_t position = 0;
	uint64_t started;

	started = benchmark ? php_pcov_dump_now() : 0;
	php_pcov_dump_prepare_hit_filter(type, filter, &selected);
	if (benchmark) {
		stats->range_filter_ns = php_pcov_dump_now() - started;
		started = php_pcov_dump_now();
	}

	if (PCG(last) != PCG(next)) {
		while (coverage) {
			if (benchmark) {
				stats->hit_entries++;
			}
			if (php_pcov_dump_hit_selected(coverage, type, &selected)) {
				if (count == SIZE_MAX) {
					*error = EOVERFLOW;
					zend_hash_destroy(&selected);
					return 0;
				}
				count++;
			}
			coverage = coverage->next;
		}
	}

	if (count > SIZE_MAX / sizeof(php_pcov_dump_hit_t)) {
		*error = EOVERFLOW;
		zend_hash_destroy(&selected);
		return 0;
	}
	if (count) {
		hits = safe_emalloc(count, sizeof(php_pcov_dump_hit_t), 0);
		if (benchmark) {
			stats->hit_array_allocations++;
			stats->hit_array_allocated_bytes +=
				count * sizeof(php_pcov_dump_hit_t);
		}
		coverage = PCG(start);
		while (coverage) {
			if (php_pcov_dump_hit_selected(coverage, type, &selected)) {
				hits[position].file = coverage->file;
				hits[position].line = coverage->line;
				position++;
			}
			coverage = coverage->next;
		}
	}
	if (benchmark) {
		stats->hit_traversal_ns = php_pcov_dump_now() - started;
	}
	zend_hash_destroy(&selected);

	*result = hits;
	*result_count = count;
	return 1;
} /* }}} */

typedef struct _php_pcov_dump_line_t {
	uint32_t line;
	int32_t value;
} php_pcov_dump_line_t;

static int php_pcov_dump_line_compare(const void *left, const void *right) { /* {{{ */
	const php_pcov_dump_line_t *first = left;
	const php_pcov_dump_line_t *second = right;
	return first->line < second->line ? -1 : first->line > second->line ? 1 : 0;
} /* }}} */

static void php_pcov_record_envelope(
		smart_str *buffer,
		uint32_t record_type,
		smart_str *payload,
		php_pcov_dump_stats_t *stats,
		zend_bool profile) { /* {{{ */
	uint32_t checksum;
	uint64_t started = profile ? php_pcov_dump_now() : 0;

	smart_str_0(payload);
	if (profile) {
		stats->validated_checksum_calls++;
		stats->validated_checksum_bytes += ZSTR_LEN(payload->s);
	}
	checksum = php_pcov_dump_checksum(ZSTR_VAL(payload->s), ZSTR_LEN(payload->s));
	if (profile) {
		stats->validated_checksum_ns += php_pcov_dump_now() - started;
		started = php_pcov_dump_now();
	}
	smart_str_appendl(buffer, "PCOVREC\0", 8);
	php_pcov_dump_append_u32(buffer, PCOV_RECORD_FORMAT_VERSION);
	php_pcov_dump_append_u32(buffer, PCOV_RECORD_HEADER_SIZE);
	php_pcov_dump_append_u32(buffer, record_type);
	php_pcov_dump_append_u32(buffer, 0);
	php_pcov_dump_append_u64(buffer, ZSTR_LEN(payload->s));
	php_pcov_dump_append_u32(buffer, checksum);
	php_pcov_dump_append_u32(buffer, PHP_VERSION_ID);
	php_pcov_dump_append_u32(buffer, PCOV_RECORD_FORMAT_COMPATIBILITY);
	php_pcov_dump_append_u32(buffer, 0);
	if (profile) started = php_pcov_dump_now();
	smart_str_appendl(buffer, ZSTR_VAL(payload->s), ZSTR_LEN(payload->s));
	if (profile) {
		stats->validated_payload_copy_ns += php_pcov_dump_now() - started;
		stats->validated_payload_copy_bytes += ZSTR_LEN(payload->s);
	}
	smart_str_0(buffer);
} /* }}} */

static zend_bool php_pcov_dump_serialize_validated_hits(
		php_pcov_manifest_file_t *files,
		size_t file_count,
		php_pcov_dump_hit_t *hits,
		size_t hit_count,
		const unsigned char environment_id[PHP_PCOV_FINGERPRINT_SIZE],
		const unsigned char manifest_id[PHP_PCOV_FINGERPRINT_SIZE],
		smart_str *buffer,
		php_pcov_dump_stats_t *stats,
		zend_bool profile,
		int *error) { /* {{{ */
	smart_str payload = {0};
	size_t index;
	uint32_t hit_files = 0;
	uint64_t started = 0;

	if (file_count > UINT32_MAX || hit_count > UINT32_MAX) {
		*error = EOVERFLOW;
		return 0;
	}
	if (hit_count) {
		if (profile) {
			stats->validated_sort_calls++;
			stats->validated_sort_entries += hit_count;
			started = php_pcov_dump_now();
		}
		qsort(hits, hit_count, sizeof(*hits), php_pcov_dump_hit_compare);
		if (profile) {
			stats->validated_serialize_sort_ns += php_pcov_dump_now() - started;
		}
	}
	if (profile) started = php_pcov_dump_now();
	for (index = 0; index < hit_count;) {
		size_t next = index + 1;
		while (next < hit_count &&
		       zend_string_equals(hits[index].file, hits[next].file)) next++;
		if (!ZSTR_LEN(hits[index].file) ||
		    ZSTR_LEN(hits[index].file) > UINT32_MAX ||
		    next - index > UINT32_MAX || hit_files == UINT32_MAX) {
			*error = EOVERFLOW;
			return 0;
		}
		hit_files++;
		index = next;
	}
	if (profile) {
		stats->validated_group_ns += php_pcov_dump_now() - started;
		stats->validated_group_entries += hit_count;
	}

	smart_str_appendl(&payload, (const char *) environment_id,
		PHP_PCOV_FINGERPRINT_SIZE);
	smart_str_appendl(&payload, (const char *) manifest_id,
		PHP_PCOV_FINGERPRINT_SIZE);
	php_pcov_dump_append_u32(&payload, PCOV_MANIFEST_REASON_MATCH);
	php_pcov_dump_append_u32(&payload, (uint32_t) file_count);
	if (profile) started = php_pcov_dump_now();
	for (index = 0; index < file_count; index++) {
		if (!ZSTR_LEN(files[index].file) ||
		    ZSTR_LEN(files[index].file) > UINT32_MAX || files[index].status) {
			*error = EINVAL;
			smart_str_free(&payload);
			return 0;
		}
		php_pcov_dump_append_u32(&payload, (uint32_t) ZSTR_LEN(files[index].file));
		smart_str_appendl(&payload, ZSTR_VAL(files[index].file),
			ZSTR_LEN(files[index].file));
		smart_str_appendl(&payload, (const char *) files[index].fingerprint,
			PHP_PCOV_FINGERPRINT_SIZE);
	}
	if (profile) {
		stats->validated_file_metadata_ns += php_pcov_dump_now() - started;
		stats->validated_file_metadata_entries += file_count;
		started = php_pcov_dump_now();
	}
	php_pcov_dump_append_u32(&payload, hit_files);
	for (index = 0; index < hit_count;) {
		size_t next = index + 1;
		size_t line;
		while (next < hit_count &&
		       zend_string_equals(hits[index].file, hits[next].file)) next++;
		php_pcov_dump_append_u32(&payload, (uint32_t) ZSTR_LEN(hits[index].file));
		smart_str_appendl(&payload, ZSTR_VAL(hits[index].file),
			ZSTR_LEN(hits[index].file));
		php_pcov_dump_append_u32(&payload, (uint32_t) (next - index));
		for (line = index; line < next; line++) {
			php_pcov_dump_append_u32(&payload, hits[line].line);
		}
		index = next;
	}
	if (profile) {
		stats->validated_emit_ns += php_pcov_dump_now() - started;
		stats->validated_emit_entries += hit_count;
		stats->validated_payload_bytes = ZSTR_LEN(payload.s);
		started = php_pcov_dump_now();
	}

	php_pcov_record_envelope(
		buffer, PCOV_RECORD_HITS, &payload, stats, profile);
	if (profile) {
		uint64_t logical = stats->hit_array_allocated_bytes +
			ZSTR_LEN(payload.s) + ZSTR_LEN(buffer->s);
		stats->validated_envelope_ns += php_pcov_dump_now() - started;
		stats->validated_output_bytes = ZSTR_LEN(buffer->s);
		if (logical > stats->temporary_logical_peak_bytes) {
			stats->temporary_logical_peak_bytes = logical;
		}
	}
	stats->coverage_files = hit_files;
	stats->executable_lines = hit_count;
	smart_str_free(&payload);
	return 1;
} /* }}} */

static zend_bool php_pcov_dump_serialize_validated_full(
		php_pcov_manifest_file_t *files,
		size_t file_count,
		zval *coverage,
		const unsigned char environment_id[PHP_PCOV_FINGERPRINT_SIZE],
		const unsigned char manifest_id[PHP_PCOV_FINGERPRINT_SIZE],
		uint32_t reason,
		smart_str *buffer,
		php_pcov_dump_stats_t *stats,
		int *error) { /* {{{ */
	smart_str payload = {0};
	size_t index;

	if (file_count > UINT32_MAX) {
		*error = EOVERFLOW;
		return 0;
	}
	smart_str_appendl(&payload, (const char *) environment_id,
		PHP_PCOV_FINGERPRINT_SIZE);
	smart_str_appendl(&payload, (const char *) manifest_id,
		PHP_PCOV_FINGERPRINT_SIZE);
	php_pcov_dump_append_u32(&payload, reason);
	php_pcov_dump_append_u32(&payload, (uint32_t) file_count);

	for (index = 0; index < file_count; index++) {
		zval *lines = zend_hash_find(Z_ARRVAL_P(coverage), files[index].file);
		php_pcov_dump_line_t *records = NULL;
		size_t count = lines && Z_TYPE_P(lines) == IS_ARRAY ?
			zend_hash_num_elements(Z_ARRVAL_P(lines)) : 0;
		size_t position = 0;

		if (!ZSTR_LEN(files[index].file) ||
		    ZSTR_LEN(files[index].file) > UINT32_MAX || count > UINT32_MAX) {
			*error = EOVERFLOW;
			smart_str_free(&payload);
			return 0;
		}
		if (count) {
			zend_ulong line;
			zval *value;
			records = safe_emalloc(count, sizeof(*records), 0);
			ZEND_HASH_FOREACH_NUM_KEY_VAL(Z_ARRVAL_P(lines), line, value) {
				if (line > UINT32_MAX || Z_TYPE_P(value) != IS_LONG ||
				    (Z_LVAL_P(value) != -1 && Z_LVAL_P(value) != 1)) {
					*error = EINVAL;
					efree(records);
					smart_str_free(&payload);
					return 0;
				}
				records[position].line = (uint32_t) line;
				records[position].value = (int32_t) Z_LVAL_P(value);
				position++;
			} ZEND_HASH_FOREACH_END();
			qsort(records, count, sizeof(*records), php_pcov_dump_line_compare);
		}

		php_pcov_dump_append_u32(&payload, (uint32_t) ZSTR_LEN(files[index].file));
		smart_str_appendl(&payload, ZSTR_VAL(files[index].file),
			ZSTR_LEN(files[index].file));
		php_pcov_dump_append_u32(&payload, files[index].status);
		smart_str_appendl(&payload, (const char *) files[index].fingerprint,
			PHP_PCOV_FINGERPRINT_SIZE);
		php_pcov_dump_append_u32(&payload, (uint32_t) count);
		for (position = 0; position < count; position++) {
			php_pcov_dump_append_u32(&payload, records[position].line);
			php_pcov_dump_append_u32(&payload, (uint32_t) records[position].value);
		}
		if (records) efree(records);
	}

	php_pcov_record_envelope(
		buffer, PCOV_RECORD_FULL, &payload, stats, 0);
	stats->coverage_files = file_count;
	smart_str_free(&payload);
	return 1;
} /* }}} */

static zend_bool php_pcov_dump_write_all(int fd, const char *data, size_t size) { /* {{{ */
	while (size) {
		ssize_t written = write(fd, data, size);

		if (written < 0) {
			if (errno == EINTR) {
				continue;
			}
			return 0;
		}

		if (written == 0) {
			errno = EIO;
			return 0;
		}

		data += written;
		size -= (size_t) written;
	}

	return 1;
} /* }}} */

static zend_bool php_pcov_dump_publish(
		zend_string *path,
		zend_string *contents,
		zend_long sequence,
		php_pcov_dump_stats_t *stats) { /* {{{ */
	char *temporary;
	size_t capacity;
	int descriptor = -1;
	int attempt;
	int saved = 0;
	uint64_t started = php_pcov_dump_now();

	if (!ZSTR_LEN(path) || strlen(ZSTR_VAL(path)) != ZSTR_LEN(path)) {
		stats->error = EINVAL;
		stats->phase = PCOV_DUMP_PHASE_OPEN;
		return 0;
	}

	capacity = ZSTR_LEN(path) + 96;
	temporary = emalloc(capacity);

	for (attempt = 0; attempt < 16; attempt++) {
		int length = snprintf(
			temporary,
			capacity,
			"%s.pcovtmp.%ld.%ld.%d",
			ZSTR_VAL(path),
			(long) getpid(),
			(long) sequence,
			attempt);

		if (length < 0 || (size_t) length >= capacity) {
			saved = ENAMETOOLONG;
			break;
		}

		descriptor = open(
			temporary,
			O_WRONLY | O_CREAT | O_EXCL | O_CLOEXEC,
			0600);

		if (descriptor >= 0 || errno != EEXIST) {
			break;
		}
	}

	if (descriptor < 0) {
		stats->error = saved ? saved : errno;
		stats->phase = PCOV_DUMP_PHASE_OPEN;
		efree(temporary);
		return 0;
	}

	if (!php_pcov_dump_write_all(
			descriptor, ZSTR_VAL(contents), ZSTR_LEN(contents))) {
		saved = errno;
		stats->phase = PCOV_DUMP_PHASE_WRITE;
		goto fail;
	}

	if (close(descriptor) != 0) {
		saved = errno;
		descriptor = -1;
		stats->phase = PCOV_DUMP_PHASE_CLOSE;
		goto fail;
	}
	descriptor = -1;

	if (rename(temporary, ZSTR_VAL(path)) != 0) {
		saved = errno;
		stats->phase = PCOV_DUMP_PHASE_RENAME;
		goto fail;
	}

	stats->bytes = ZSTR_LEN(contents);
	stats->write_ns = php_pcov_dump_now() - started;
	efree(temporary);
	return 1;

fail:
	if (descriptor >= 0) {
		close(descriptor);
	}
	unlink(temporary);
	stats->error = saved;
	stats->write_ns = php_pcov_dump_now() - started;
	efree(temporary);
	return 0;
} /* }}} */

static zend_bool php_pcov_dump_export(
		zend_string *path,
		const unsigned char environment_id[PHP_PCOV_FINGERPRINT_SIZE],
		zend_long type,
		zval *filter,
		zend_long sequence,
		php_pcov_dump_stats_t *stats) { /* {{{ */
	php_pcov_manifest_file_t *files = NULL;
	size_t file_count = 0;
	zval coverage;
	smart_str serialized = {0};
	uint64_t started;
	uint32_t reason = PCOV_MANIFEST_REASON_MATCH;
	unsigned char zero_id[PHP_PCOV_FINGERPRINT_SIZE] = {0};
	int error = 0;
	zend_bool success;

	started = php_pcov_dump_now();
	php_pcov_collect_data_force_profiled(
		type, filter, &coverage,
		php_pcov_dump_benchmark_enabled() ? stats : NULL);
	stats->collect_ns = php_pcov_dump_now() - started;
	if (!php_pcov_manifest_collect_files(
			type, filter, &files, &file_count, stats, &reason, &error)) {
		stats->error = error;
		stats->phase = PCOV_DUMP_PHASE_COLLECT;
		zval_ptr_dtor(&coverage);
		return 0;
	}

	started = php_pcov_dump_now();
	success = php_pcov_dump_serialize_validated_full(
		files, file_count, &coverage, environment_id, zero_id,
		PCOV_MANIFEST_REASON_EXPLICIT_FULL,
		&serialized, stats, &error);
	stats->serialize_ns = php_pcov_dump_now() - started;

	if (!success) {
		stats->error = error;
		stats->phase = PCOV_DUMP_PHASE_SERIALIZE;
		if (files) efree(files);
		zval_ptr_dtor(&coverage);
		smart_str_free(&serialized);
		return 0;
	}

	success = php_pcov_dump_publish(
		path, serialized.s, sequence, stats);

	if (files) efree(files);
	zval_ptr_dtor(&coverage);
	smart_str_free(&serialized);
	return success;
} /* }}} */

static zend_bool php_pcov_dump_export_validated(
		zend_string *path,
		zend_string *manifest_path,
		const unsigned char environment_id[PHP_PCOV_FINGERPRINT_SIZE],
		zend_long type,
		zval *filter,
		zend_long sequence,
		php_pcov_dump_stats_t *stats,
		unsigned char manifest_id[PHP_PCOV_FINGERPRINT_SIZE]) { /* {{{ */
	php_pcov_manifest_file_t *files = NULL;
	size_t file_count = 0;
	php_pcov_manifest_view_t manifest;
	php_pcov_dump_hit_t *hits = NULL;
	size_t hit_count = 0;
	uint32_t reason = PCOV_MANIFEST_REASON_MATCH;
	int error = 0;
	zend_bool loaded;
	zend_bool matched = 0;
	zend_bool success;
	zend_bool benchmark = php_pcov_dump_benchmark_enabled();
	uint64_t started = php_pcov_dump_now();
	smart_str serialized = {0};

	memset(&manifest, 0, sizeof(manifest));
	memset(manifest_id, 0, PHP_PCOV_FINGERPRINT_SIZE);
	if (!php_pcov_manifest_collect_files(
			type, filter, &files, &file_count, stats, &reason, &error)) {
		stats->error = error;
		stats->phase = PCOV_DUMP_PHASE_COLLECT;
		return 0;
	}

	started = php_pcov_dump_now();
	loaded = php_pcov_manifest_load(
		manifest_path, &manifest, &reason, &error, stats, benchmark);
	stats->manifest_parse_ns = php_pcov_dump_now() - started;
	if (loaded) {
		memcpy(manifest_id, manifest.manifest_id, PHP_PCOV_FINGERPRINT_SIZE);
		if (reason == PCOV_MANIFEST_REASON_MATCH) {
			started = benchmark ? php_pcov_dump_now() : 0;
			matched = php_pcov_manifest_validate(
				&manifest, environment_id, files, file_count, &reason);
			if (benchmark) {
				stats->manifest_match_ns += php_pcov_dump_now() - started;
			}
		}
	}
	if (matched) {
		started = php_pcov_dump_now();
		success = php_pcov_dump_collect_hits(
			type, filter, &hits, &hit_count, stats, 1, &error);
		stats->collect_ns = php_pcov_dump_now() - started;
		if (!success) {
			stats->error = error;
			stats->phase = PCOV_DUMP_PHASE_COLLECT;
			goto cleanup;
		}
		/* Some generated op arrays execute bookkeeping opcodes carrying line 0.
		 * CFG discovery does not expose those as executable coverage lines, so
		 * normal pcov\collect() cannot return them.  They are trace artifacts,
		 * not representable coverage records. */
		if (hit_count) {
			size_t read;
			size_t write = 0;
			for (read = 0; read < hit_count; read++) {
				if (hits[read].line != 0) {
					hits[write++] = hits[read];
				}
			}
			hit_count = write;
		}
		started = php_pcov_dump_now();
		if (!php_pcov_manifest_validate_hits(
				&manifest, hits, hit_count, stats, benchmark)) {
			matched = 0;
			reason = PCOV_MANIFEST_REASON_UNKNOWN_HIT;
		}
		started = php_pcov_dump_now() - started;
		stats->validation_ns += started;
		if (benchmark) stats->manifest_hit_validation_ns += started;
	}
	stats->validation_ns += stats->fingerprint_ns + stats->manifest_parse_ns;
	stats->manifest_match = matched;
	stats->full_fallback = !matched;
	stats->mismatch_reason = reason;

	if (matched) {
		started = php_pcov_dump_now();
		success = php_pcov_dump_serialize_validated_hits(
			files, file_count, hits, hit_count, environment_id,
			manifest.manifest_id, &serialized, stats, benchmark, &error);
		stats->serialize_ns = php_pcov_dump_now() - started;
	} else {
		zval coverage;

		started = php_pcov_dump_now();
		php_pcov_collect_data_force_profiled(
			type, filter, &coverage, stats);
		stats->collect_ns = php_pcov_dump_now() - started;
		started = php_pcov_dump_now();
		success = php_pcov_dump_serialize_validated_full(
			files, file_count, &coverage, environment_id, manifest_id,
			reason, &serialized, stats, &error);
		stats->serialize_ns = php_pcov_dump_now() - started;
		zval_ptr_dtor(&coverage);
	}

cleanup:
	if (hits) efree(hits);
	if (loaded) php_pcov_manifest_view_destroy(&manifest);
	if (files) efree(files);
	if (!success) {
		stats->error = error;
		stats->phase = PCOV_DUMP_PHASE_SERIALIZE;
		smart_str_free(&serialized);
		return 0;
	}
	success = php_pcov_dump_publish(path, serialized.s, sequence, stats);
	smart_str_free(&serialized);
	return success;
} /* }}} */

static zend_bool php_pcov_dump_valid_type(zend_long type) { /* {{{ */
	if (type == PCOV_FILTER_ALL ||
	    type == PCOV_FILTER_INCLUDE ||
	    type == PCOV_FILTER_EXCLUDE) {
		return 1;
	}

	zend_throw_error(zend_ce_type_error,
		"type must be "
			"\\pcov\\inclusive, "
			"\\pcov\\exclusive, or \\pcov\\all");
	return 0;
} /* }}} */

static void php_pcov_dump_warn(php_pcov_dump_stats_t *stats) { /* {{{ */
	if (stats->error) {
		php_error_docref(NULL, E_WARNING,
			"coverage %s failed: %s",
			php_pcov_dump_phase_name(stats->phase),
			strerror(stats->error));
	} else {
		php_error_docref(NULL, E_WARNING,
			"coverage %s failed",
			php_pcov_dump_phase_name(stats->phase));
	}
} /* }}} */

#endif /* HAVE_PCOV_NATIVE_EXPORT */

#ifndef HAVE_PCOV_NATIVE_EXPORT
PHP_PCOV_API uint64_t php_pcov_dump_now(void) { /* {{{ */
	return 0;
} /* }}} */

PHP_PCOV_API zend_bool php_pcov_dump_benchmark_enabled(void) { /* {{{ */
	return 0;
} /* }}} */
#endif

PHP_PCOV_API void php_pcov_dump_rinit(void) { /* {{{ */
	memset(&PCG(dump_stats), 0, sizeof(PCG(dump_stats)));
	PCG(dump_sequence) = 0;
} /* }}} */

PHP_NAMED_FUNCTION(php_pcov_export) { /* {{{ */
	zend_string *path;
	zend_long type = PCOV_FILTER_ALL;
	zval *filter = NULL;
	zend_string *manifest_path = NULL;
	zend_string *deployment_hex = NULL;
#ifdef HAVE_PCOV_NATIVE_EXPORT
	unsigned char deployment_id[PHP_PCOV_FINGERPRINT_SIZE];
	unsigned char manifest_id[PHP_PCOV_FINGERPRINT_SIZE];
#endif

	if (zend_parse_parameters(
			ZEND_NUM_ARGS(), "S|S!S!la", &path,
			&manifest_path, &deployment_hex, &type, &filter) != SUCCESS) {
		return;
	}

	if (!php_pcov_api_enabled() || !PCG(ini.large_codebase)) {
		RETURN_FALSE;
	}
	if (manifest_path != NULL && deployment_hex == NULL) {
		zend_throw_error(zend_ce_type_error,
			"deployment_id is required when manifest is provided");
		return;
	}

#ifdef HAVE_PCOV_NATIVE_EXPORT
	{
		php_coverage_t **previous = PCG(last);
		struct rusage before;
		struct rusage after;
		zend_bool success;
		zend_string *id_hex;
		uint64_t export_started;

		if (!php_pcov_dump_valid_type(type)) {
			return;
		}
		if (deployment_hex &&
		    !php_pcov_dump_hex_id(deployment_hex, deployment_id)) {
			zend_throw_error(zend_ce_type_error,
				"deployment_id must be exactly 64 hexadecimal characters");
			return;
		}

		memset(&PCG(dump_stats), 0, sizeof(PCG(dump_stats)));
		PCG(dump_stats).valid = 1;
		PCG(dump_sequence)++;
		getrusage(RUSAGE_SELF, &before);
		export_started = php_pcov_dump_now();
		if (!deployment_hex) {
			memset(deployment_id, 0, sizeof(deployment_id));
		}
		if (manifest_path) {
			success = php_pcov_dump_export_validated(
				path, manifest_path, deployment_id, type, filter,
				PCG(dump_sequence), &PCG(dump_stats), manifest_id);
		} else {
			memset(manifest_id, 0, sizeof(manifest_id));
			success = php_pcov_dump_export(
				path, deployment_id, type, filter,
				PCG(dump_sequence), &PCG(dump_stats));
		}
		PCG(dump_stats).export_ns = php_pcov_dump_now() - export_started;
		getrusage(RUSAGE_SELF, &after);
		php_pcov_dump_usage_delta(&PCG(dump_stats), &before, &after);

		if (!success) {
			PCG(last) = previous;
			php_pcov_dump_warn(&PCG(dump_stats));
			RETURN_FALSE;
		}

		array_init(return_value);
		if (manifest_path) {
			add_assoc_string(return_value, "mode", PCG(dump_stats).manifest_match ?
				"hit-only" : "full-fallback");
			add_assoc_string(return_value, "reason", (char *)
				php_pcov_manifest_reason_name(PCG(dump_stats).mismatch_reason));
		} else {
			add_assoc_string(return_value, "mode", "full");
			add_assoc_string(return_value, "reason", "explicit-full");
		}
		id_hex = php_pcov_dump_id_hex(manifest_id);
		add_assoc_str(return_value, "manifest_id", id_hex);
		add_assoc_long(return_value, "validation_files",
			(zend_long) PCG(dump_stats).validation_files);
		add_assoc_long(return_value, "validation_ns",
			(zend_long) PCG(dump_stats).validation_ns);
		add_assoc_long(return_value, "fingerprint_ns",
			(zend_long) PCG(dump_stats).fingerprint_ns);
		add_assoc_long(return_value, "manifest_parse_ns",
			(zend_long) PCG(dump_stats).manifest_parse_ns);
	}
#else
	(void) manifest_path;
	(void) deployment_hex;
	(void) type;
	(void) filter;
	php_error_docref(NULL, E_WARNING,
		"large-codebase coverage export is unavailable on this platform");
	RETURN_FALSE;
#endif
} /* }}} */

PHP_NAMED_FUNCTION(php_pcov_export_stats) { /* {{{ */
	if (zend_parse_parameters_none() != SUCCESS) {
		return;
	}

	array_init(return_value);
	if (!PCG(dump_stats).valid) {
		return;
	}

	add_assoc_long(return_value, "collect_ns", (zend_long) PCG(dump_stats).collect_ns);
	add_assoc_long(return_value, "range_filter_ns", (zend_long) PCG(dump_stats).range_filter_ns);
	add_assoc_long(return_value, "hit_traversal_ns", (zend_long) PCG(dump_stats).hit_traversal_ns);
	add_assoc_long(return_value, "cfg_discovery_ns", (zend_long) PCG(dump_stats).cfg_discovery_ns);
	add_assoc_long(return_value, "array_construction_ns", (zend_long) PCG(dump_stats).array_construction_ns);
	add_assoc_long(return_value, "native_record_ns", (zend_long) PCG(dump_stats).native_record_ns);
	add_assoc_long(return_value, "serialize_ns", (zend_long) PCG(dump_stats).serialize_ns);
	add_assoc_long(return_value, "write_ns", (zend_long) PCG(dump_stats).write_ns);
	add_assoc_long(return_value, "manifest_parse_ns", (zend_long) PCG(dump_stats).manifest_parse_ns);
	add_assoc_long(return_value, "manifest_open_ns", (zend_long) PCG(dump_stats).manifest_open_ns);
	add_assoc_long(return_value, "manifest_stat_ns", (zend_long) PCG(dump_stats).manifest_stat_ns);
	add_assoc_long(return_value, "manifest_allocation_ns", (zend_long) PCG(dump_stats).manifest_allocation_ns);
	add_assoc_long(return_value, "manifest_read_ns", (zend_long) PCG(dump_stats).manifest_read_ns);
	add_assoc_long(return_value, "manifest_close_ns", (zend_long) PCG(dump_stats).manifest_close_ns);
	add_assoc_long(return_value, "manifest_header_ns", (zend_long) PCG(dump_stats).manifest_header_ns);
	add_assoc_long(return_value, "manifest_checksum_ns", (zend_long) PCG(dump_stats).manifest_checksum_ns);
	add_assoc_long(return_value, "manifest_record_ns", (zend_long) PCG(dump_stats).manifest_record_ns);
	add_assoc_long(return_value, "manifest_match_ns", (zend_long) PCG(dump_stats).manifest_match_ns);
	add_assoc_long(return_value, "manifest_hit_sort_ns", (zend_long) PCG(dump_stats).manifest_hit_sort_ns);
	add_assoc_long(return_value, "manifest_hit_file_lookup_ns", (zend_long) PCG(dump_stats).manifest_hit_file_lookup_ns);
	add_assoc_long(return_value, "manifest_hit_line_lookup_ns", (zend_long) PCG(dump_stats).manifest_hit_line_lookup_ns);
	add_assoc_long(return_value, "manifest_hit_validation_ns", (zend_long) PCG(dump_stats).manifest_hit_validation_ns);
	add_assoc_long(return_value, "manifest_open_calls", (zend_long) PCG(dump_stats).manifest_open_calls);
	add_assoc_long(return_value, "manifest_stat_calls", (zend_long) PCG(dump_stats).manifest_stat_calls);
	add_assoc_long(return_value, "manifest_allocations", (zend_long) PCG(dump_stats).manifest_allocations);
	add_assoc_long(return_value, "manifest_allocated_bytes", (zend_long) PCG(dump_stats).manifest_allocated_bytes);
	add_assoc_long(return_value, "manifest_read_calls", (zend_long) PCG(dump_stats).manifest_read_calls);
	add_assoc_long(return_value, "manifest_bytes_read", (zend_long) PCG(dump_stats).manifest_bytes_read);
	add_assoc_long(return_value, "manifest_checksum_calls", (zend_long) PCG(dump_stats).manifest_checksum_calls);
	add_assoc_long(return_value, "manifest_records_validated", (zend_long) PCG(dump_stats).manifest_records_validated);
	add_assoc_long(return_value, "manifest_lines_validated", (zend_long) PCG(dump_stats).manifest_lines_validated);
	add_assoc_long(return_value, "manifest_hit_files_looked_up", (zend_long) PCG(dump_stats).manifest_hit_files_looked_up);
	add_assoc_long(return_value, "manifest_hit_entries_validated", (zend_long) PCG(dump_stats).manifest_hit_entries_validated);
	add_assoc_long(return_value, "manifest_hit_duplicates", (zend_long) PCG(dump_stats).manifest_hit_duplicates);
	add_assoc_long(return_value, "manifest_hit_line_comparisons", (zend_long) PCG(dump_stats).manifest_hit_line_comparisons);
	add_assoc_long(return_value, "manifest_hit_line_records_decoded", (zend_long) PCG(dump_stats).manifest_hit_line_records_decoded);
	add_assoc_long(return_value, "manifest_hit_search_restarts", (zend_long) PCG(dump_stats).manifest_hit_search_restarts);
	add_assoc_long(return_value, "manifest_hit_matches", (zend_long) PCG(dump_stats).manifest_hit_matches);
	add_assoc_long(return_value, "manifest_hit_unknown_lines", (zend_long) PCG(dump_stats).manifest_hit_unknown_lines);
	add_assoc_long(return_value, "manifest_hit_unknown_files", (zend_long) PCG(dump_stats).manifest_hit_unknown_files);
	add_assoc_long(return_value, "validated_serialize_sort_ns", (zend_long) PCG(dump_stats).validated_serialize_sort_ns);
	add_assoc_long(return_value, "validated_group_ns", (zend_long) PCG(dump_stats).validated_group_ns);
	add_assoc_long(return_value, "validated_payload_size_ns", (zend_long) PCG(dump_stats).validated_payload_size_ns);
	add_assoc_long(return_value, "validated_file_metadata_ns", (zend_long) PCG(dump_stats).validated_file_metadata_ns);
	add_assoc_long(return_value, "validated_emit_ns", (zend_long) PCG(dump_stats).validated_emit_ns);
	add_assoc_long(return_value, "validated_envelope_ns", (zend_long) PCG(dump_stats).validated_envelope_ns);
	add_assoc_long(return_value, "validated_checksum_ns", (zend_long) PCG(dump_stats).validated_checksum_ns);
	add_assoc_long(return_value, "validated_payload_copy_ns", (zend_long) PCG(dump_stats).validated_payload_copy_ns);
	add_assoc_long(return_value, "validated_sort_calls", (zend_long) PCG(dump_stats).validated_sort_calls);
	add_assoc_long(return_value, "validated_sort_entries", (zend_long) PCG(dump_stats).validated_sort_entries);
	add_assoc_long(return_value, "validated_group_entries", (zend_long) PCG(dump_stats).validated_group_entries);
	add_assoc_long(return_value, "validated_payload_size_entries", (zend_long) PCG(dump_stats).validated_payload_size_entries);
	add_assoc_long(return_value, "validated_file_metadata_entries", (zend_long) PCG(dump_stats).validated_file_metadata_entries);
	add_assoc_long(return_value, "validated_emit_entries", (zend_long) PCG(dump_stats).validated_emit_entries);
	add_assoc_long(return_value, "validated_checksum_calls", (zend_long) PCG(dump_stats).validated_checksum_calls);
	add_assoc_long(return_value, "validated_checksum_bytes", (zend_long) PCG(dump_stats).validated_checksum_bytes);
	add_assoc_long(return_value, "validated_payload_copy_bytes", (zend_long) PCG(dump_stats).validated_payload_copy_bytes);
	add_assoc_long(return_value, "validated_payload_bytes", (zend_long) PCG(dump_stats).validated_payload_bytes);
	add_assoc_long(return_value, "validated_output_bytes", (zend_long) PCG(dump_stats).validated_output_bytes);
	add_assoc_long(return_value, "hit_array_allocations", (zend_long) PCG(dump_stats).hit_array_allocations);
	add_assoc_long(return_value, "hit_array_allocated_bytes", (zend_long) PCG(dump_stats).hit_array_allocated_bytes);
	add_assoc_long(return_value, "temporary_logical_peak_bytes", (zend_long) PCG(dump_stats).temporary_logical_peak_bytes);
	add_assoc_long(return_value, "export_ns", (zend_long) PCG(dump_stats).export_ns);
	add_assoc_long(return_value, "fingerprint_ns", (zend_long) PCG(dump_stats).fingerprint_ns);
	add_assoc_long(return_value, "validation_ns", (zend_long) PCG(dump_stats).validation_ns);
	add_assoc_long(return_value, "compile_fingerprint_ns", (zend_long) PCG(dump_stats).compile_fingerprint_ns);
	add_assoc_long(return_value, "bytes", (zend_long) PCG(dump_stats).bytes);
	add_assoc_long(return_value, "collection_files", (zend_long) PCG(dump_stats).collection_files);
	add_assoc_long(return_value, "discovery_operations", (zend_long) PCG(dump_stats).discovery_operations);
	add_assoc_long(return_value, "cfg_op_arrays", (zend_long) PCG(dump_stats).cfg_op_arrays);
	add_assoc_long(return_value, "hit_entries", (zend_long) PCG(dump_stats).hit_entries);
	add_assoc_long(return_value, "coverage_files", (zend_long) PCG(dump_stats).coverage_files);
	add_assoc_long(return_value, "executable_lines", (zend_long) PCG(dump_stats).executable_lines);
	add_assoc_long(return_value, "validation_files", (zend_long) PCG(dump_stats).validation_files);
	add_assoc_long(return_value, "compile_fingerprint_files", (zend_long) PCG(dump_stats).compile_fingerprint_files);
	add_assoc_long(return_value, "fingerprint_calls", (zend_long) PCG(dump_stats).fingerprint_metrics.calls);
	add_assoc_long(return_value, "fingerprint_open_calls", (zend_long) PCG(dump_stats).fingerprint_metrics.open_calls);
	add_assoc_long(return_value, "fingerprint_open_ns", (zend_long) PCG(dump_stats).fingerprint_metrics.open_ns);
	add_assoc_long(return_value, "fingerprint_stat_calls", (zend_long) PCG(dump_stats).fingerprint_metrics.stat_calls);
	add_assoc_long(return_value, "fingerprint_stat_ns", (zend_long) PCG(dump_stats).fingerprint_metrics.stat_ns);
	add_assoc_long(return_value, "fingerprint_read_calls", (zend_long) PCG(dump_stats).fingerprint_metrics.read_calls);
	add_assoc_long(return_value, "fingerprint_read_ns", (zend_long) PCG(dump_stats).fingerprint_metrics.read_ns);
	add_assoc_long(return_value, "fingerprint_bytes_read", (zend_long) PCG(dump_stats).fingerprint_metrics.bytes_read);
	add_assoc_long(return_value, "fingerprint_sha_ns", (zend_long) PCG(dump_stats).fingerprint_metrics.sha_ns);
	add_assoc_long(return_value, "fingerprint_close_ns", (zend_long) PCG(dump_stats).fingerprint_metrics.close_ns);
	add_assoc_long(return_value, "fingerprint_races", (zend_long) PCG(dump_stats).fingerprint_metrics.races);
	add_assoc_long(return_value, "fingerprint_failures", (zend_long) PCG(dump_stats).fingerprint_metrics.failures);
	add_assoc_long(return_value, "compile_fingerprint_calls", (zend_long) PCG(dump_stats).compile_fingerprint_metrics.calls);
	add_assoc_long(return_value, "compile_fingerprint_open_calls", (zend_long) PCG(dump_stats).compile_fingerprint_metrics.open_calls);
	add_assoc_long(return_value, "compile_fingerprint_open_ns", (zend_long) PCG(dump_stats).compile_fingerprint_metrics.open_ns);
	add_assoc_long(return_value, "compile_fingerprint_stat_calls", (zend_long) PCG(dump_stats).compile_fingerprint_metrics.stat_calls);
	add_assoc_long(return_value, "compile_fingerprint_stat_ns", (zend_long) PCG(dump_stats).compile_fingerprint_metrics.stat_ns);
	add_assoc_long(return_value, "compile_fingerprint_read_calls", (zend_long) PCG(dump_stats).compile_fingerprint_metrics.read_calls);
	add_assoc_long(return_value, "compile_fingerprint_read_ns", (zend_long) PCG(dump_stats).compile_fingerprint_metrics.read_ns);
	add_assoc_long(return_value, "compile_fingerprint_bytes_read", (zend_long) PCG(dump_stats).compile_fingerprint_metrics.bytes_read);
	add_assoc_long(return_value, "compile_fingerprint_sha_ns", (zend_long) PCG(dump_stats).compile_fingerprint_metrics.sha_ns);
	add_assoc_long(return_value, "compile_fingerprint_close_ns", (zend_long) PCG(dump_stats).compile_fingerprint_metrics.close_ns);
	add_assoc_long(return_value, "compile_fingerprint_races", (zend_long) PCG(dump_stats).compile_fingerprint_metrics.races);
	add_assoc_long(return_value, "compile_fingerprint_failures", (zend_long) PCG(dump_stats).compile_fingerprint_metrics.failures);
	add_assoc_long(return_value, "fingerprint_reuse_checks", (zend_long) PCG(dump_stats).fingerprint_metrics.reuse_checks);
	add_assoc_long(return_value, "fingerprint_reuse_hits", (zend_long) PCG(dump_stats).fingerprint_metrics.reuse_hits);
	add_assoc_long(return_value, "fingerprint_reuse_misses", (zend_long) PCG(dump_stats).fingerprint_metrics.reuse_misses);
	add_assoc_long(return_value, "fingerprint_reuse_ns", (zend_long) PCG(dump_stats).fingerprint_metrics.reuse_ns);
	add_assoc_bool(return_value, "manifest_match", PCG(dump_stats).manifest_match);
	add_assoc_bool(return_value, "full_fallback", PCG(dump_stats).full_fallback);
	add_assoc_long(return_value, "mismatch_reason", PCG(dump_stats).mismatch_reason);
#ifdef HAVE_PCOV_NATIVE_EXPORT
	add_assoc_string(return_value, "mismatch_reason_name", (char *)
		php_pcov_manifest_reason_name(PCG(dump_stats).mismatch_reason));
#else
	add_assoc_string(return_value, "mismatch_reason_name", "unsupported");
#endif
	add_assoc_long(return_value, "user_us", (zend_long) PCG(dump_stats).user_us);
	add_assoc_long(return_value, "system_us", (zend_long) PCG(dump_stats).system_us);
	add_assoc_long(return_value, "minor_faults", (zend_long) PCG(dump_stats).minor_faults);
	add_assoc_long(return_value, "major_faults", (zend_long) PCG(dump_stats).major_faults);
	add_assoc_long(return_value, "max_rss_kb", (zend_long) PCG(dump_stats).max_rss_kb);
	add_assoc_long(return_value, "error", PCG(dump_stats).error);
	add_assoc_string(return_value, "phase",
		(char *) php_pcov_dump_phase_name(PCG(dump_stats).phase));
} /* }}} */

/*
 * Local variables:
 * tab-width: 4
 * c-basic-offset: 4
 * End:
 * vim600: noet sw=4 ts=4 fdm=marker
 * vim<600: noet sw=4 ts=4
 */
