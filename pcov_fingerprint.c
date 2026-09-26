/*
  +----------------------------------------------------------------------+
  | PCOV manifest source fingerprints                                    |
  +----------------------------------------------------------------------+
  | Use PHP SHA-256 while retaining source identity and race checks.      |
  +----------------------------------------------------------------------+
*/

#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include "php.h"
#include "ext/hash/php_hash.h"
#include "ext/hash/php_hash_sha.h"
#include "pcov_fingerprint.h"

#include <errno.h>
#include <fcntl.h>
#include <stdint.h>
#include <string.h>
#include <sys/stat.h>
#include <time.h>

#ifdef PHP_WIN32

zend_bool php_pcov_fingerprint_file(
		zend_string *path,
		unsigned char fingerprint[PHP_PCOV_FINGERPRINT_SIZE],
		int *error,
		php_pcov_fingerprint_metrics_t *metrics,
		php_pcov_fingerprint_identity_t *identity) { /* {{{ */
	(void) path;
	(void) fingerprint;
	(void) metrics;
	(void) identity;
	*error = ENOTSUP;
	return 0;
} /* }}} */

zend_bool php_pcov_fingerprint_identity_matches(
		zend_string *path,
		const php_pcov_fingerprint_identity_t *expected,
		int *error,
		php_pcov_fingerprint_metrics_t *metrics) { /* {{{ */
	(void) path;
	(void) expected;
	(void) metrics;
	*error = ENOTSUP;
	return 0;
} /* }}} */

#else
# include <unistd.h>
# define PCOV_CLOSE close
# define PCOV_READ read

#ifndef O_BINARY
# define O_BINARY 0
#endif

#ifndef O_CLOEXEC
# define O_CLOEXEC 0
#endif

static uint64_t php_pcov_fingerprint_now(void) {
#ifdef CLOCK_MONOTONIC
	struct timespec now;

	if (clock_gettime(CLOCK_MONOTONIC, &now) == 0) {
		return ((uint64_t) now.tv_sec * 1000000000ULL) +
			(uint64_t) now.tv_nsec;
	}
#endif
	return 0;
}

static zend_bool php_pcov_fingerprint_same_file(
		const struct stat *before, const struct stat *after) {
	if (!(before->st_dev == after->st_dev &&
		before->st_ino == after->st_ino &&
		before->st_mode == after->st_mode &&
		before->st_size == after->st_size &&
		before->st_mtime == after->st_mtime &&
		before->st_ctime == after->st_ctime)) {
		return 0;
	}
#if defined(__APPLE__)
	return before->st_mtimespec.tv_nsec == after->st_mtimespec.tv_nsec &&
		before->st_ctimespec.tv_nsec == after->st_ctimespec.tv_nsec;
#else
	return before->st_mtim.tv_nsec == after->st_mtim.tv_nsec &&
		before->st_ctim.tv_nsec == after->st_ctim.tv_nsec;
#endif
}

static void php_pcov_fingerprint_identity_from_stat(
		const struct stat *status,
		php_pcov_fingerprint_identity_t *identity) {
	memset(identity, 0, sizeof(*identity));
	identity->device = (uint64_t) status->st_dev;
	identity->inode = (uint64_t) status->st_ino;
	identity->size = (uint64_t) status->st_size;
	identity->mode = (uint32_t) status->st_mode;
#if defined(__APPLE__)
	identity->mtime_sec = (int64_t) status->st_mtimespec.tv_sec;
	identity->mtime_nsec = (int64_t) status->st_mtimespec.tv_nsec;
	identity->ctime_sec = (int64_t) status->st_ctimespec.tv_sec;
	identity->ctime_nsec = (int64_t) status->st_ctimespec.tv_nsec;
#else
	identity->mtime_sec = (int64_t) status->st_mtim.tv_sec;
	identity->mtime_nsec = (int64_t) status->st_mtim.tv_nsec;
	identity->ctime_sec = (int64_t) status->st_ctim.tv_sec;
	identity->ctime_nsec = (int64_t) status->st_ctim.tv_nsec;
#endif
}

static zend_bool php_pcov_fingerprint_identity_equal(
		const php_pcov_fingerprint_identity_t *first,
		const php_pcov_fingerprint_identity_t *second) {
	return memcmp(first, second, sizeof(*first)) == 0;
}

zend_bool php_pcov_fingerprint_identity_matches(
		zend_string *path,
		const php_pcov_fingerprint_identity_t *expected,
		int *error,
		php_pcov_fingerprint_metrics_t *metrics) { /* {{{ */
	php_pcov_fingerprint_identity_t current;
	struct stat status;
	uint64_t started = metrics ? php_pcov_fingerprint_now() : 0;
	uint64_t elapsed;

	if (metrics) {
		metrics->reuse_checks++;
		metrics->stat_calls++;
	}
	if (!path || !ZSTR_LEN(path) ||
	    strlen(ZSTR_VAL(path)) != ZSTR_LEN(path) ||
	    VCWD_STAT(ZSTR_VAL(path), &status) != 0 || !S_ISREG(status.st_mode)) {
		*error = errno ? errno : EINVAL;
		if (metrics) {
			metrics->reuse_misses++;
			metrics->failures++;
			metrics->reuse_ns += php_pcov_fingerprint_now() - started;
		}
		return 0;
	}
	php_pcov_fingerprint_identity_from_stat(&status, &current);
	if (metrics) {
		elapsed = php_pcov_fingerprint_now() - started;
		metrics->reuse_ns += elapsed;
		metrics->stat_ns += elapsed;
	}
	if (!php_pcov_fingerprint_identity_equal(expected, &current)) {
		*error = EAGAIN;
		if (metrics) {
			metrics->reuse_misses++;
		}
		return 0;
	}
	*error = 0;
	if (metrics) {
		metrics->reuse_hits++;
	}
	return 1;
} /* }}} */

zend_bool php_pcov_fingerprint_file(
		zend_string *path,
		unsigned char fingerprint[PHP_PCOV_FINGERPRINT_SIZE],
		int *error,
		php_pcov_fingerprint_metrics_t *metrics,
		php_pcov_fingerprint_identity_t *identity) { /* {{{ */
	unsigned char buffer[32768];
	PHP_SHA256_CTX context;
	struct stat before;
	struct stat after;
	struct stat path_after;
	int descriptor;
	uint64_t started = 0;

	if (metrics) {
		metrics->calls++;
	}

	if (!path || !ZSTR_LEN(path) ||
	    strlen(ZSTR_VAL(path)) != ZSTR_LEN(path)) {
		*error = EINVAL;
		if (metrics) {
			metrics->failures++;
		}
		return 0;
	}

	if (metrics) {
		metrics->open_calls++;
		started = php_pcov_fingerprint_now();
	}
	descriptor = VCWD_OPEN(ZSTR_VAL(path), O_RDONLY | O_BINARY | O_CLOEXEC);
	if (metrics) {
		metrics->open_ns += php_pcov_fingerprint_now() - started;
	}
	if (descriptor < 0) {
		*error = errno;
		if (metrics) {
			metrics->failures++;
		}
		return 0;
	}
	if (metrics) {
		metrics->stat_calls++;
		started = php_pcov_fingerprint_now();
	}
	if (fstat(descriptor, &before) != 0 || !S_ISREG(before.st_mode)) {
		if (metrics) {
			metrics->stat_ns += php_pcov_fingerprint_now() - started;
			metrics->failures++;
		}
		*error = errno ? errno : EINVAL;
		PCOV_CLOSE(descriptor);
		return 0;
	}
	if (metrics) {
		metrics->stat_ns += php_pcov_fingerprint_now() - started;
	}

	PHP_SHA256Init(&context);
	while (1) {
		if (metrics) {
			metrics->read_calls++;
			started = php_pcov_fingerprint_now();
		}
		ssize_t received = PCOV_READ(descriptor, buffer, sizeof(buffer));
		if (metrics) {
			metrics->read_ns += php_pcov_fingerprint_now() - started;
		}

		if (received < 0) {
			if (errno == EINTR) {
				continue;
			}
			*error = errno;
			if (metrics) {
				metrics->failures++;
			}
			PCOV_CLOSE(descriptor);
			return 0;
		}
		if (received == 0) {
			break;
		}
		if (metrics) {
			metrics->bytes_read += (uint64_t) received;
			started = php_pcov_fingerprint_now();
		}
		PHP_SHA256Update(&context, buffer, (size_t) received);
		if (metrics) {
			metrics->sha_ns += php_pcov_fingerprint_now() - started;
		}
	}

	if (metrics) {
		metrics->stat_calls++;
		started = php_pcov_fingerprint_now();
	}
	if (fstat(descriptor, &after) != 0 ||
	    !php_pcov_fingerprint_same_file(&before, &after)) {
		if (metrics) {
			metrics->stat_ns += php_pcov_fingerprint_now() - started;
			metrics->races++;
			metrics->failures++;
		}
		*error = errno ? errno : EAGAIN;
		PCOV_CLOSE(descriptor);
		return 0;
	}
	if (metrics) {
		metrics->stat_ns += php_pcov_fingerprint_now() - started;
		metrics->stat_calls++;
		started = php_pcov_fingerprint_now();
	}
	if (VCWD_STAT(ZSTR_VAL(path), &path_after) != 0 ||
	    !php_pcov_fingerprint_same_file(&after, &path_after)) {
		if (metrics) {
			metrics->stat_ns += php_pcov_fingerprint_now() - started;
			metrics->races++;
			metrics->failures++;
		}
		*error = errno ? errno : EAGAIN;
		PCOV_CLOSE(descriptor);
		return 0;
	}
	if (metrics) {
		metrics->stat_ns += php_pcov_fingerprint_now() - started;
		started = php_pcov_fingerprint_now();
	}
	if (PCOV_CLOSE(descriptor) != 0) {
		if (metrics) {
			metrics->close_ns += php_pcov_fingerprint_now() - started;
			metrics->failures++;
		}
		*error = errno;
		return 0;
	}
	if (metrics) {
		metrics->close_ns += php_pcov_fingerprint_now() - started;
		started = php_pcov_fingerprint_now();
	}

	PHP_SHA256Final(fingerprint, &context);
	if (metrics) {
		metrics->sha_ns += php_pcov_fingerprint_now() - started;
	}
	if (identity) {
		php_pcov_fingerprint_identity_from_stat(&after, identity);
	}
	*error = 0;
	return 1;
} /* }}} */

#endif

/*
 * Local variables:
 * tab-width: 4
 * c-basic-offset: 4
 * End:
 * vim600: noet sw=4 ts=4 fdm=marker
 * vim<600: noet sw=4 ts=4
 */
