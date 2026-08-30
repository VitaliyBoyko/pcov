/*
  +----------------------------------------------------------------------+
  | Experimental PCOV manifest fingerprints                             |
  +----------------------------------------------------------------------+
*/

#ifndef PCOV_FINGERPRINT_H
#define PCOV_FINGERPRINT_H

#include "php.h"

#define PHP_PCOV_FINGERPRINT_SIZE 32

typedef struct _php_pcov_fingerprint_metrics_t {
	uint64_t calls;
	uint64_t open_calls;
	uint64_t open_ns;
	uint64_t stat_calls;
	uint64_t stat_ns;
	uint64_t read_calls;
	uint64_t read_ns;
	uint64_t bytes_read;
	uint64_t sha_ns;
	uint64_t close_ns;
	uint64_t races;
	uint64_t failures;
	uint64_t reuse_checks;
	uint64_t reuse_hits;
	uint64_t reuse_misses;
	uint64_t reuse_ns;
} php_pcov_fingerprint_metrics_t;

typedef struct _php_pcov_fingerprint_identity_t {
	uint64_t device;
	uint64_t inode;
	uint64_t size;
	uint32_t mode;
	int64_t mtime_sec;
	int64_t mtime_nsec;
	int64_t ctime_sec;
	int64_t ctime_nsec;
} php_pcov_fingerprint_identity_t;

zend_bool php_pcov_fingerprint_file(
	zend_string *path,
	unsigned char fingerprint[PHP_PCOV_FINGERPRINT_SIZE],
	int *error,
	php_pcov_fingerprint_metrics_t *metrics,
	php_pcov_fingerprint_identity_t *identity);

zend_bool php_pcov_fingerprint_identity_matches(
	zend_string *path,
	const php_pcov_fingerprint_identity_t *expected,
	int *error,
	php_pcov_fingerprint_metrics_t *metrics);

#endif
