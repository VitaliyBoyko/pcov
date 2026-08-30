/*
  +----------------------------------------------------------------------+
  | Experimental PCOV manifest fingerprints                             |
  +----------------------------------------------------------------------+
  | This is intentionally self-contained.  The manifest experiment must |
  | not depend on ext/hash being loaded in every instrumented process.   |
  +----------------------------------------------------------------------+
*/

#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include "php.h"
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

typedef struct _php_pcov_sha256_t {
	uint32_t state[8];
	uint64_t bytes;
	unsigned char block[64];
	size_t used;
} php_pcov_sha256_t;

static const uint32_t php_pcov_sha256_constants[64] = {
	0x428a2f98U, 0x71374491U, 0xb5c0fbcfU, 0xe9b5dba5U,
	0x3956c25bU, 0x59f111f1U, 0x923f82a4U, 0xab1c5ed5U,
	0xd807aa98U, 0x12835b01U, 0x243185beU, 0x550c7dc3U,
	0x72be5d74U, 0x80deb1feU, 0x9bdc06a7U, 0xc19bf174U,
	0xe49b69c1U, 0xefbe4786U, 0x0fc19dc6U, 0x240ca1ccU,
	0x2de92c6fU, 0x4a7484aaU, 0x5cb0a9dcU, 0x76f988daU,
	0x983e5152U, 0xa831c66dU, 0xb00327c8U, 0xbf597fc7U,
	0xc6e00bf3U, 0xd5a79147U, 0x06ca6351U, 0x14292967U,
	0x27b70a85U, 0x2e1b2138U, 0x4d2c6dfcU, 0x53380d13U,
	0x650a7354U, 0x766a0abbU, 0x81c2c92eU, 0x92722c85U,
	0xa2bfe8a1U, 0xa81a664bU, 0xc24b8b70U, 0xc76c51a3U,
	0xd192e819U, 0xd6990624U, 0xf40e3585U, 0x106aa070U,
	0x19a4c116U, 0x1e376c08U, 0x2748774cU, 0x34b0bcb5U,
	0x391c0cb3U, 0x4ed8aa4aU, 0x5b9cca4fU, 0x682e6ff3U,
	0x748f82eeU, 0x78a5636fU, 0x84c87814U, 0x8cc70208U,
	0x90befffaU, 0xa4506cebU, 0xbef9a3f7U, 0xc67178f2U
};

static uint32_t php_pcov_sha256_rotate(uint32_t value, unsigned int count) {
	return (value >> count) | (value << (32U - count));
}

static uint32_t php_pcov_sha256_read(const unsigned char *bytes) {
	return ((uint32_t) bytes[0] << 24) |
		((uint32_t) bytes[1] << 16) |
		((uint32_t) bytes[2] << 8) |
		(uint32_t) bytes[3];
}

static void php_pcov_sha256_transform(
		php_pcov_sha256_t *context, const unsigned char block[64]) {
	uint32_t words[64];
	uint32_t a, b, c, d, e, f, g, h;
	unsigned int index;

	for (index = 0; index < 16; index++) {
		words[index] = php_pcov_sha256_read(block + (index * 4));
	}
	for (; index < 64; index++) {
		uint32_t x = words[index - 15];
		uint32_t y = words[index - 2];
		uint32_t s0 = php_pcov_sha256_rotate(x, 7) ^
			php_pcov_sha256_rotate(x, 18) ^ (x >> 3);
		uint32_t s1 = php_pcov_sha256_rotate(y, 17) ^
			php_pcov_sha256_rotate(y, 19) ^ (y >> 10);
		words[index] = words[index - 16] + s0 + words[index - 7] + s1;
	}

	a = context->state[0]; b = context->state[1];
	c = context->state[2]; d = context->state[3];
	e = context->state[4]; f = context->state[5];
	g = context->state[6]; h = context->state[7];

	for (index = 0; index < 64; index++) {
		uint32_t s1 = php_pcov_sha256_rotate(e, 6) ^
			php_pcov_sha256_rotate(e, 11) ^ php_pcov_sha256_rotate(e, 25);
		uint32_t choice = (e & f) ^ ((~e) & g);
		uint32_t first = h + s1 + choice +
			php_pcov_sha256_constants[index] + words[index];
		uint32_t s0 = php_pcov_sha256_rotate(a, 2) ^
			php_pcov_sha256_rotate(a, 13) ^ php_pcov_sha256_rotate(a, 22);
		uint32_t majority = (a & b) ^ (a & c) ^ (b & c);
		uint32_t second = s0 + majority;

		h = g; g = f; f = e; e = d + first;
		d = c; c = b; b = a; a = first + second;
	}

	context->state[0] += a; context->state[1] += b;
	context->state[2] += c; context->state[3] += d;
	context->state[4] += e; context->state[5] += f;
	context->state[6] += g; context->state[7] += h;
}

static void php_pcov_sha256_init(php_pcov_sha256_t *context) {
	static const uint32_t initial[8] = {
		0x6a09e667U, 0xbb67ae85U, 0x3c6ef372U, 0xa54ff53aU,
		0x510e527fU, 0x9b05688cU, 0x1f83d9abU, 0x5be0cd19U
	};

	memcpy(context->state, initial, sizeof(initial));
	context->bytes = 0;
	context->used = 0;
}

static void php_pcov_sha256_update(
		php_pcov_sha256_t *context, const unsigned char *data, size_t length) {
	context->bytes += length;
	while (length) {
		size_t available = sizeof(context->block) - context->used;
		size_t copy = length < available ? length : available;

		memcpy(context->block + context->used, data, copy);
		context->used += copy;
		data += copy;
		length -= copy;
		if (context->used == sizeof(context->block)) {
			php_pcov_sha256_transform(context, context->block);
			context->used = 0;
		}
	}
}

static void php_pcov_sha256_final(
		php_pcov_sha256_t *context,
		unsigned char result[PHP_PCOV_FINGERPRINT_SIZE]) {
	uint64_t bits = context->bytes * 8U;
	unsigned int index;

	context->block[context->used++] = 0x80;
	if (context->used > 56) {
		memset(context->block + context->used, 0, 64 - context->used);
		php_pcov_sha256_transform(context, context->block);
		context->used = 0;
	}
	memset(context->block + context->used, 0, 56 - context->used);
	for (index = 0; index < 8; index++) {
		context->block[63 - index] = (unsigned char) (bits >> (index * 8));
	}
	php_pcov_sha256_transform(context, context->block);

	for (index = 0; index < 8; index++) {
		result[index * 4] = (unsigned char) (context->state[index] >> 24);
		result[index * 4 + 1] = (unsigned char) (context->state[index] >> 16);
		result[index * 4 + 2] = (unsigned char) (context->state[index] >> 8);
		result[index * 4 + 3] = (unsigned char) context->state[index];
	}
	memset(context, 0, sizeof(*context));
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
	php_pcov_sha256_t context;
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

	php_pcov_sha256_init(&context);
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
		php_pcov_sha256_update(&context, buffer, (size_t) received);
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

	php_pcov_sha256_final(&context, fingerprint);
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
