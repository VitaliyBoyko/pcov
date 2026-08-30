/*
  +----------------------------------------------------------------------+
  | Copyright (c) The PHP Group                                          |
  +----------------------------------------------------------------------+
  | This source file is subject to version 3.01 of the PHP license,      |
  | that is bundled with this package in the file LICENSE, and is        |
  | available through the world-wide-web at the following url:           |
  | http://www.php.net/license/3_01.txt                                  |
  | If you did not receive a copy of the PHP license and are unable to   |
  | obtain it through the world-wide-web, please send a note to          |
  | license@php.net so we can mail you a copy immediately.               |
  +----------------------------------------------------------------------+
  | Author: krakjoe                                                      |
  +----------------------------------------------------------------------+
*/
/* $Id$ */

#ifndef PHP_PCOV_H
#define PHP_PCOV_H

extern zend_module_entry pcov_module_entry;
#define phpext_pcov_ptr &pcov_module_entry

#define PHP_PCOV_VERSION "2.0.0"

#ifdef PHP_WIN32
#	define PHP_PCOV_API __declspec(dllexport)
#elif defined(__GNUC__) && __GNUC__ >= 4
#	define PHP_PCOV_API __attribute__ ((visibility("default")))
#else
#	define PHP_PCOV_API
#endif

#ifdef ZTS
#include "TSRM.h"
#endif

#include "pcov_fingerprint.h"

typedef struct _php_coverage_t php_coverage_t;
typedef struct _php_pcov_dump_stats_t php_pcov_dump_stats_t;

struct _php_coverage_t {
	zend_string    *file;
	uint32_t        line;
	php_coverage_t *next;
};

struct _php_pcov_dump_stats_t {
	uint64_t collect_ns;
	uint64_t range_filter_ns;
	uint64_t hit_traversal_ns;
	uint64_t cfg_discovery_ns;
	uint64_t array_construction_ns;
	uint64_t native_record_ns;
	uint64_t serialize_ns;
	uint64_t write_ns;
	uint64_t manifest_parse_ns;
	uint64_t manifest_open_ns;
	uint64_t manifest_stat_ns;
	uint64_t manifest_allocation_ns;
	uint64_t manifest_read_ns;
	uint64_t manifest_close_ns;
	uint64_t manifest_header_ns;
	uint64_t manifest_checksum_ns;
	uint64_t manifest_record_ns;
	uint64_t manifest_match_ns;
	uint64_t manifest_hit_sort_ns;
	uint64_t manifest_hit_file_lookup_ns;
	uint64_t manifest_hit_line_lookup_ns;
	uint64_t manifest_hit_validation_ns;
	uint64_t manifest_open_calls;
	uint64_t manifest_stat_calls;
	uint64_t manifest_allocations;
	uint64_t manifest_allocated_bytes;
	uint64_t manifest_read_calls;
	uint64_t manifest_bytes_read;
	uint64_t manifest_checksum_calls;
	uint64_t manifest_records_validated;
	uint64_t manifest_lines_validated;
	uint64_t manifest_hit_files_looked_up;
	uint64_t manifest_hit_entries_validated;
	uint64_t manifest_hit_duplicates;
	uint64_t manifest_hit_line_comparisons;
	uint64_t manifest_hit_line_records_decoded;
	uint64_t manifest_hit_search_restarts;
	uint64_t manifest_hit_matches;
	uint64_t manifest_hit_unknown_lines;
	uint64_t manifest_hit_unknown_files;
	uint64_t validated_serialize_sort_ns;
	uint64_t validated_group_ns;
	uint64_t validated_payload_size_ns;
	uint64_t validated_file_metadata_ns;
	uint64_t validated_emit_ns;
	uint64_t validated_envelope_ns;
	uint64_t validated_checksum_ns;
	uint64_t validated_payload_copy_ns;
	uint64_t validated_sort_calls;
	uint64_t validated_sort_entries;
	uint64_t validated_group_entries;
	uint64_t validated_payload_size_entries;
	uint64_t validated_file_metadata_entries;
	uint64_t validated_emit_entries;
	uint64_t validated_checksum_calls;
	uint64_t validated_checksum_bytes;
	uint64_t validated_payload_copy_bytes;
	uint64_t validated_payload_bytes;
	uint64_t validated_output_bytes;
	uint64_t hit_array_allocations;
	uint64_t hit_array_allocated_bytes;
	uint64_t temporary_logical_peak_bytes;
	uint64_t export_ns;
	uint64_t fingerprint_ns;
	uint64_t validation_ns;
	uint64_t compile_fingerprint_ns;
	uint64_t bytes;
	uint64_t collection_files;
	uint64_t discovery_operations;
	uint64_t cfg_op_arrays;
	uint64_t hit_entries;
	uint64_t coverage_files;
	uint64_t executable_lines;
	uint64_t validation_files;
	uint64_t compile_fingerprint_files;
	php_pcov_fingerprint_metrics_t fingerprint_metrics;
	php_pcov_fingerprint_metrics_t compile_fingerprint_metrics;
	int64_t  user_us;
	int64_t  system_us;
	int64_t  minor_faults;
	int64_t  major_faults;
	int64_t  max_rss_kb;
	int       error;
	int       phase;
	zend_bool valid;
	zend_bool manifest_match;
	zend_bool full_fallback;
	uint32_t mismatch_reason;
};

ZEND_BEGIN_MODULE_GLOBALS(pcov)
	zend_bool         enabled;
	zend_arena       *mem;
	php_coverage_t   *start;
	php_coverage_t  **next;
	php_coverage_t  **last;
	HashTable         waiting;
	HashTable         files;
	HashTable         ignores;
	HashTable         wants;
	HashTable         discovered;
	HashTable         covered;
	HashTable         fingerprints;
	php_pcov_dump_stats_t dump_stats;
	zend_long         dump_sequence;
	uint64_t          compile_fingerprint_ns;
	uint64_t          compile_fingerprint_files;
	php_pcov_fingerprint_metrics_t compile_fingerprint_metrics;
	zend_string      *directory;
	pcre_cache_entry *exclude;
	struct {
		zend_bool enabled;
		zend_bool large_codebase;
		zend_long memory;
		zend_long files;
		char     *directory;
		char     *exclude;
	} ini;
ZEND_END_MODULE_GLOBALS(pcov)

ZEND_EXTERN_MODULE_GLOBALS(pcov)

#define PCG(v) ZEND_MODULE_GLOBALS_ACCESSOR(pcov, v)

PHP_PCOV_API void php_pcov_collect_data(zend_long type, zval *filter, zval *return_value);
PHP_PCOV_API void php_pcov_collect_data_profiled(
	zend_long type, zval *filter, zval *return_value, php_pcov_dump_stats_t *stats);
PHP_PCOV_API void php_pcov_collect_data_force_profiled(
	zend_long type, zval *filter, zval *return_value, php_pcov_dump_stats_t *stats);
PHP_PCOV_API uint64_t php_pcov_dump_now(void);
PHP_PCOV_API zend_bool php_pcov_dump_benchmark_enabled(void);
PHP_PCOV_API bool php_pcov_api_enabled(void);
PHP_PCOV_API void php_pcov_dump_rinit(void);

PHP_NAMED_FUNCTION(php_pcov_export);
PHP_NAMED_FUNCTION(php_pcov_export_stats);

#if defined(ZTS) && defined(COMPILE_DL_PCOV)
ZEND_TSRMLS_CACHE_EXTERN()
#endif

#endif	/* PHP_PCOV_H */

/*
 * Local variables:
 * tab-width: 4
 * c-basic-offset: 4
 * End:
 * vim600: noet sw=4 ts=4 fdm=marker
 * vim<600: noet sw=4 ts=4
 */
