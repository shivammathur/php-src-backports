/*
   +----------------------------------------------------------------------+
   | PHP Version 5                                                        |
   +----------------------------------------------------------------------+
   | Copyright (c) 1997-2015 The PHP Group                                |
   +----------------------------------------------------------------------+
   | This source file is subject to version 3.01 of the PHP license,      |
   | that is bundled with this package in the file LICENSE, and is        |
   | available through the world-wide-web at the following url:           |
   | http://www.php.net/license/3_01.txt                                  |
   | If you did not receive a copy of the PHP license and are unable to   |
   | obtain it through the world-wide-web, please send a note to          |
   | license@php.net so we can mail you a copy immediately.               |
   +----------------------------------------------------------------------+
   | Authors: Rasmus Lerdorf <rasmus@php.net>                             |
   |          Jim Winstead <jimw@php.net>                                 |
   |          Hartmut Holzgraefe <hholzgra@php.net>                       |
   |          Wez Furlong <wez@thebrainroom.com>                          |
   |          Sara Golemon <pollita@php.net>                              |
   +----------------------------------------------------------------------+
 */
/* $Id$ */

#include "php.h"
#include "php_globals.h"
#include "php_streams.h"
#include "php_network.h"
#include "php_ini.h"
#include "ext/standard/basic_functions.h"
#include "ext/standard/php_smart_str.h"

#include <stdio.h>
#include <stdlib.h>
#include <errno.h>
#include <sys/types.h>
#include <sys/stat.h>
#include <fcntl.h>

#ifdef PHP_WIN32
#define O_RDONLY _O_RDONLY
#include "win32/param.h"
#else
#include <sys/param.h>
#endif

#include "php_standard.h"

#include <sys/types.h>
#if HAVE_SYS_SOCKET_H
#include <sys/socket.h>
#endif

#ifdef PHP_WIN32
#include <winsock2.h>
#elif defined(NETWARE) && defined(USE_WINSOCK)
#include <novsock2.h>
#else
#include <netinet/in.h>
#include <netdb.h>
#if HAVE_ARPA_INET_H
#include <arpa/inet.h>
#endif
#endif

#if defined(PHP_WIN32) || defined(__riscos__) || defined(NETWARE)
#undef AF_UNIX
#endif

#if defined(AF_UNIX)
#include <sys/un.h>
#endif

#include "php_fopen_wrappers.h"

#define HTTP_HEADER_BLOCK_SIZE			1024
#define HTTP_HEADER_MAX_LOCATION_SIZE	8182 /* 8192 - 10 (size of "Location: ") */
#define PHP_URL_REDIRECT_MAX			20
#define HTTP_HEADER_USER_AGENT			1
#define HTTP_HEADER_HOST				2
#define HTTP_HEADER_AUTH				4
#define HTTP_HEADER_FROM				8
#define HTTP_HEADER_CONTENT_LENGTH		16
#define HTTP_HEADER_TYPE				32
#define HTTP_HEADER_CONNECTION			64

#define HTTP_WRAPPER_HEADER_INIT    1
#define HTTP_WRAPPER_REDIRECTED     2

static inline void strip_header(char *header_bag, char *lc_header_bag,
		const char *lc_header_name)
{
	char *lc_header_start = strstr(lc_header_bag, lc_header_name);
	char *header_start = header_bag + (lc_header_start - lc_header_bag);

	if (lc_header_start
	&& (lc_header_start == lc_header_bag || *(lc_header_start-1) == '\n')
	) {
		char *lc_eol = strchr(lc_header_start, '\n');
		char *eol = header_start + (lc_eol - lc_header_start);

		if (lc_eol) {
			size_t eollen = strlen(lc_eol);

			memmove(lc_header_start, lc_eol+1, eollen);
			memmove(header_start, eol+1, eollen);
		} else {
			*lc_header_start = '\0';
			*header_start = '\0';
		}
	}
}

typedef struct _php_stream_http_response_header_info {
	php_stream_filter *transfer_encoding;
	size_t file_size;
	zend_bool error;
	zend_bool follow_location;
	char *location;
	size_t location_len;
} php_stream_http_response_header_info;

static void php_stream_http_response_header_info_init(
		php_stream_http_response_header_info *header_info)
{
	memset(header_info, 0, sizeof(php_stream_http_response_header_info));
	header_info->follow_location = 1;
}

/* Trim white spaces from response header line and update its length */
static zend_bool php_stream_http_response_header_trim(char *http_header_line,
		size_t *http_header_line_length)
{
	char *http_header_line_end = http_header_line + *http_header_line_length - 1;
	zend_bool space_trim;
	while (http_header_line_end >= http_header_line &&
			(*http_header_line_end == '\n' || *http_header_line_end == '\r')) {
		http_header_line_end--;
	}

	/* The primary definition of an HTTP header in RFC 7230 states:
	* > Each header field consists of a case-insensitive field name followed
	* > by a colon (":"), optional leading whitespace, the field value, and
	* > optional trailing whitespace. */

	/* Strip trailing whitespace */
	space_trim = (*http_header_line_end == ' ' || *http_header_line_end == '\t');
	if (space_trim) {
		do {
			http_header_line_end--;
		} while (http_header_line_end >= http_header_line &&
				(*http_header_line_end == ' ' || *http_header_line_end == '\t'));
	}
	http_header_line_end++;
	*http_header_line_end = '\0';
	*http_header_line_length = http_header_line_end - http_header_line;

	return space_trim;
}

/* Process folding headers of the current line and if there are none, parse last full response
 * header line. It returns NULL if the last header is finished, otherwise it returns updated
 * last header line.  */
static char *php_stream_http_response_headers_parse(php_stream_wrapper *wrapper,
		php_stream *stream, php_stream_context *context, int options,
		char *last_header_line_str, char *header_line, size_t *header_line_length,
		int response_code, zval *response_header,
		php_stream_http_response_header_info *header_info,
		size_t *last_header_line_len)
{
	size_t last_header_line_length;
	char *last_header_line_end;
	char *last_header_value;
	zend_bool store_header = 1;
	zval **tmpzval = NULL;
	if(!last_header_line_len)
		return NULL;
	last_header_line_length = *last_header_line_len;
	last_header_line_end = last_header_line_str + last_header_line_length - 1;

	/* Process non empty header line. */
	if (header_line && (*header_line != '\n' && *header_line != '\r')) {
		/* Removing trailing white spaces. */
		if (php_stream_http_response_header_trim(header_line, header_line_length) &&
				*header_line_length == 0) {
			/* Only spaces so treat as an empty folding header. */
			return last_header_line_str;
		}

		/* Process folding headers if starting with a space or a tab. */
		if (header_line && (*header_line == ' ' || *header_line == '\t')) {
			char *http_folded_header_line = header_line;
			size_t http_folded_header_line_length = *header_line_length;
			char *extended_header_str;
			/* Remove the leading white spaces. */
			while (*http_folded_header_line == ' ' || *http_folded_header_line == '\t') {
				http_folded_header_line++;
				http_folded_header_line_length--;
			}
			/* It has to have some characters because it would get returned after the call
			 * php_stream_http_response_header_trim above. */
			if(http_folded_header_line_length == 0 || http_folded_header_line_length > *header_line_length)
				return NULL;
			/* Concatenate last header line, space and current header line. */
			extended_header_str = zend_string_concat3(
					last_header_line_str, last_header_line_length,
					" ", 1,
					http_folded_header_line, http_folded_header_line_length,
					last_header_line_len);
			efree(last_header_line_str);
			last_header_line_str = extended_header_str;
			/* Return new header line. */
			return last_header_line_str;
		}
	}

	/* Find header separator position. */
	last_header_value = memchr(last_header_line_str, ':', last_header_line_length);
	if (last_header_value) {
		/* Verify there is no space in header name */
		char *last_header_name = last_header_line_str + 1;
		while (last_header_name < last_header_value) {
			if (*last_header_name == ' ' || *last_header_name == '\t') {
				header_info->error = 1;
				php_stream_wrapper_log_error(wrapper, options,
					"HTTP invalid response format (space in header name)!");
				efree(last_header_line_str);
				return NULL;
			}
			++last_header_name;
		}

		last_header_value++; /* Skip ':'. */

		/* Strip leading whitespace. */
		while (last_header_value < last_header_line_end
				&& (*last_header_value == ' ' || *last_header_value == '\t')) {
			last_header_value++;
		}
	} else {
		/* There is no colon which means invalid response so error. */
		header_info->error = 1;
		php_stream_wrapper_log_error(wrapper, options,
				"HTTP invalid response format (no colon in header line)!");
		efree(last_header_line_str);
		return NULL;
	}


	if (!strncasecmp(last_header_line_str, "Location:", sizeof("Location:")-1)) {
		size_t last_header_value_len;
		/* Check if the location should be followed. */
		if (context && php_stream_context_get_option(context, "http", "follow_location", &tmpzval) == SUCCESS) {
			header_info->follow_location = zval_is_true(*tmpzval);
		} else if (!((response_code >= 300 && response_code < 304)
				|| 307 == response_code || 308 == response_code)) {
			/* The redirection should not be automatic if follow_location is not set and
			 * response_code not in (300, 301, 302, 303 and 307)
			 * see http://www.w3.org/Protocols/rfc2616/rfc2616-sec10.html#sec10.3.1
			 * RFC 7238 defines 308: http://tools.ietf.org/html/rfc7238 */
			header_info->follow_location = 0;
		}
		last_header_value_len = strlen(last_header_value);
		if (last_header_value_len > HTTP_HEADER_MAX_LOCATION_SIZE) {
			header_info->error = 1;
			php_stream_wrapper_log_error(wrapper, options,
					"HTTP Location header size is over the limit of %d bytes",
					HTTP_HEADER_MAX_LOCATION_SIZE);
			efree(last_header_line_str);
			return NULL;
		}
		if (header_info->location_len == 0) {
			header_info->location = emalloc(last_header_value_len + 1);
		} else if (header_info->location_len <= last_header_value_len) {
			header_info->location = erealloc(header_info->location, last_header_value_len + 1);
		}
		header_info->location_len = last_header_value_len;
		memcpy(header_info->location, last_header_value, last_header_value_len + 1);
	} else if (!strncasecmp(last_header_line_str, "Content-Type:", sizeof("Content-Type:")-1)) {
		php_stream_notify_info(context, PHP_STREAM_NOTIFY_MIME_TYPE_IS, last_header_value, 0);
	} else if (!strncasecmp(last_header_line_str, "Content-Length:", sizeof("Content-Length:")-1)) {
		/* https://www.rfc-editor.org/rfc/rfc9110.html#name-content-length */
		const char *ptr = last_header_value;
		/* must contain only digits, no + or - symbols */
		if (*ptr >= '0' && *ptr <= '9') {
			char* endptr = NULL;
			size_t parsed = strtoul(ptr, &endptr, 10);
			/* check whether there was no garbage in the header value and the conversion was successful */
			if (endptr && !*endptr) {
				/* truncate for 32-bit such that no negative file sizes occur */
				header_info->file_size = MIN(parsed, LONG_MAX);
				php_stream_notify_file_size(context, header_info->file_size, last_header_line_str, 0);
			}
		}
	} else if (
		!strncasecmp(last_header_line_str, "Transfer-Encoding:", sizeof("Transfer-Encoding:")-1)
		&& !strncasecmp(last_header_value, "Chunked", sizeof("Chunked")-1)
	) {
		/* Create filter to decode response body. */
		if (!(options & STREAM_ONLY_GET_HEADERS)) {
			long decode = 1;

			if (context && php_stream_context_get_option(context, "http", "auto_decode", &tmpzval) == SUCCESS) {
				decode = zend_is_true(*tmpzval);
			}
			if (decode) {
				if (header_info->transfer_encoding != NULL) {
					/* Prevent a memory leak in case there are more transfer-encoding headers. */
					php_stream_filter_free(header_info->transfer_encoding);
				}
				header_info->transfer_encoding = php_stream_filter_create(
						"dechunk", NULL, php_stream_is_persistent(stream));
				if (header_info->transfer_encoding != NULL) {
					/* Do not store transfer-encoding header. */
					store_header = 0;
				}
			}
		}
	}

	if (store_header) {
		zval *http_response;

		MAKE_STD_ZVAL(http_response);
		ZVAL_STRINGL(http_response, last_header_line_str, last_header_line_length, 1);
		zend_hash_next_index_insert(Z_ARRVAL_P(response_header), &http_response, sizeof(zval *), NULL);
	} else {
		efree(last_header_line_str);
	}

	return NULL;
}

php_stream *php_stream_url_wrap_http_ex(php_stream_wrapper *wrapper, char *path, char *mode, int options, char **opened_path, php_stream_context *context, int redirect_max, int flags STREAMS_DC TSRMLS_DC) /* {{{ */
{
	php_stream *stream = NULL;
	php_url *resource = NULL;
	int use_ssl;
	int use_proxy = 0;
	char *scratch = NULL;
	char *tmp = NULL;
	char *ua_str = NULL;
	zval **ua_zval = NULL, **tmpzval = NULL;
	int scratch_len = 0;
	zval *response_header = NULL;
	int reqok = 0;
	char *http_header_line = NULL;
	char *last_header_line_str = NULL;
	size_t last_header_line_length = 0;
	php_stream_http_response_header_info header_info;
	char tmp_line[128];
	size_t chunk_size = 0;
	int eol_detect = 0;
	char *transport_string, *errstr = NULL;
	int transport_len, have_header = 0, request_fulluri = 0, ignore_errors = 0;
	char *protocol_version = NULL;
	int protocol_version_len = 3; /* Default: "1.0" */
	struct timeval timeout;
	char *user_headers = NULL;
	int header_init = ((flags & HTTP_WRAPPER_HEADER_INIT) != 0);
	int redirected = ((flags & HTTP_WRAPPER_REDIRECTED) != 0);
	int response_code;

	tmp_line[0] = '\0';

	if (redirect_max < 1) {
		php_stream_wrapper_log_error(wrapper, options TSRMLS_CC, "Redirection limit reached, aborting");
		return NULL;
	}

	resource = php_url_parse(path);
	if (resource == NULL) {
		return NULL;
	}

	if (strncasecmp(resource->scheme, "http", sizeof("http")-1) && strncasecmp(resource->scheme, "https", sizeof("https")-1)) {
		if (!context ||
			php_stream_context_get_option(context, wrapper->wops->label, "proxy", &tmpzval) == FAILURE ||
			Z_TYPE_PP(tmpzval) != IS_STRING ||
			Z_STRLEN_PP(tmpzval) == 0) {
			php_url_free(resource);
			return php_stream_open_wrapper_ex(path, mode, REPORT_ERRORS, NULL, context);
		}
		/* Called from a non-http wrapper with http proxying requested (i.e. ftp) */
		request_fulluri = 1;
		use_ssl = 0;
		use_proxy = 1;

		transport_len = Z_STRLEN_PP(tmpzval);
		transport_string = estrndup(Z_STRVAL_PP(tmpzval), Z_STRLEN_PP(tmpzval));
	} else {
		/* Normal http request (possibly with proxy) */

		if (strpbrk(mode, "awx+")) {
			php_stream_wrapper_log_error(wrapper, options TSRMLS_CC, "HTTP wrapper does not support writeable connections");
			php_url_free(resource);
			return NULL;
		}

		/* Should we send the entire path in the request line, default to no. */
		if (context &&
			php_stream_context_get_option(context, "http", "request_fulluri", &tmpzval) == SUCCESS) {
			zval ztmp = **tmpzval;

			zval_copy_ctor(&ztmp);
			convert_to_boolean(&ztmp);
			request_fulluri = Z_BVAL(ztmp) ? 1 : 0;
			zval_dtor(&ztmp);
		}

		use_ssl = resource->scheme && (strlen(resource->scheme) > 4) && resource->scheme[4] == 's';
		/* choose default ports */
		if (use_ssl && resource->port == 0)
			resource->port = 443;
		else if (resource->port == 0)
			resource->port = 80;

		if (context &&
			php_stream_context_get_option(context, wrapper->wops->label, "proxy", &tmpzval) == SUCCESS &&
			Z_TYPE_PP(tmpzval) == IS_STRING &&
			Z_STRLEN_PP(tmpzval) > 0) {
			use_proxy = 1;
			transport_len = Z_STRLEN_PP(tmpzval);
			transport_string = estrndup(Z_STRVAL_PP(tmpzval), Z_STRLEN_PP(tmpzval));
		} else {
			transport_len = spprintf(&transport_string, 0, "%s://%s:%d", use_ssl ? "ssl" : "tcp", resource->host, resource->port);
		}
	}

	if (request_fulluri && (strchr(path, '\n') != NULL || strchr(path, '\r') != NULL)) {
		php_stream_wrapper_log_error(wrapper, options TSRMLS_CC, "HTTP wrapper full URI path does not allow CR or LF characters");
		php_url_free(resource);
		efree(transport_string);
		return NULL;
	}

	if (context && php_stream_context_get_option(context, wrapper->wops->label, "timeout", &tmpzval) == SUCCESS) {
		SEPARATE_ZVAL(tmpzval);
		convert_to_double_ex(tmpzval);
		timeout.tv_sec = (time_t) Z_DVAL_PP(tmpzval);
		timeout.tv_usec = (size_t) ((Z_DVAL_PP(tmpzval) - timeout.tv_sec) * 1000000);
	} else {
		timeout.tv_sec = FG(default_socket_timeout);
		timeout.tv_usec = 0;
	}

	stream = php_stream_xport_create(transport_string, transport_len, options,
			STREAM_XPORT_CLIENT | STREAM_XPORT_CONNECT,
			NULL, &timeout, context, &errstr, NULL);

	if (stream) {
		php_stream_set_option(stream, PHP_STREAM_OPTION_READ_TIMEOUT, 0, &timeout);
	}

	if (errstr) {
		php_stream_wrapper_log_error(wrapper, options TSRMLS_CC, "%s", errstr);
		efree(errstr);
		errstr = NULL;
	}

	efree(transport_string);

	if (stream && use_proxy && use_ssl) {
		smart_str header = {0};

		smart_str_appendl(&header, "CONNECT ", sizeof("CONNECT ")-1);
		smart_str_appends(&header, resource->host);
		smart_str_appendc(&header, ':');
		smart_str_append_unsigned(&header, resource->port);
		smart_str_appendl(&header, " HTTP/1.0\r\n", sizeof(" HTTP/1.0\r\n")-1);

	    /* check if we have Proxy-Authorization header */
		if (context && php_stream_context_get_option(context, "http", "header", &tmpzval) == SUCCESS) {
			char *s, *p;

			if (Z_TYPE_PP(tmpzval) == IS_ARRAY) {
				HashPosition pos;
				zval **tmpheader = NULL;

				for (zend_hash_internal_pointer_reset_ex(Z_ARRVAL_PP(tmpzval), &pos);
					SUCCESS == zend_hash_get_current_data_ex(Z_ARRVAL_PP(tmpzval), (void *)&tmpheader, &pos);
					zend_hash_move_forward_ex(Z_ARRVAL_PP(tmpzval), &pos)) {
					if (Z_TYPE_PP(tmpheader) == IS_STRING) {
						s = Z_STRVAL_PP(tmpheader);
						do {
							while (*s == ' ' || *s == '\t') s++;
							p = s;
							while (*p != 0 && *p != ':' && *p != '\r' && *p !='\n') p++;
							if (*p == ':') {
								p++;
								if (p - s == sizeof("Proxy-Authorization:") - 1 &&
								    zend_binary_strcasecmp(s, sizeof("Proxy-Authorization:") - 1,
								        "Proxy-Authorization:", sizeof("Proxy-Authorization:") - 1) == 0) {
									while (*p != 0 && *p != '\r' && *p !='\n') p++;
									smart_str_appendl(&header, s, p - s);
									smart_str_appendl(&header, "\r\n", sizeof("\r\n")-1);
									goto finish;
								} else {
									while (*p != 0 && *p != '\r' && *p !='\n') p++;
								}
							}
							s = p;
							while (*s == '\r' || *s == '\n') s++;
						} while (*s != 0);
					}
				}
			} else if (Z_TYPE_PP(tmpzval) == IS_STRING && Z_STRLEN_PP(tmpzval)) {
				s = Z_STRVAL_PP(tmpzval);
				do {
					while (*s == ' ' || *s == '\t') s++;
					p = s;
					while (*p != 0 && *p != ':' && *p != '\r' && *p !='\n') p++;
					if (*p == ':') {
						p++;
						if (p - s == sizeof("Proxy-Authorization:") - 1 &&
						    zend_binary_strcasecmp(s, sizeof("Proxy-Authorization:") - 1,
						        "Proxy-Authorization:", sizeof("Proxy-Authorization:") - 1) == 0) {
							while (*p != 0 && *p != '\r' && *p !='\n') p++;
							smart_str_appendl(&header, s, p - s);
							smart_str_appendl(&header, "\r\n", sizeof("\r\n")-1);
							goto finish;
						} else {
							while (*p != 0 && *p != '\r' && *p !='\n') p++;
						}
					}
					s = p;
					while (*s == '\r' || *s == '\n') s++;
				} while (*s != 0);
			}
		}
finish:
		smart_str_appendl(&header, "\r\n", sizeof("\r\n")-1);

		if (php_stream_write(stream, header.c, header.len) != header.len) {
			php_stream_wrapper_log_error(wrapper, options TSRMLS_CC, "Cannot connect to HTTPS server through proxy");
			php_stream_close(stream);
			stream = NULL;
		}
 	 	smart_str_free(&header);

 	 	if (stream) {
 	 		char header_line[HTTP_HEADER_BLOCK_SIZE];

			/* get response header */
			while (php_stream_gets(stream, header_line, HTTP_HEADER_BLOCK_SIZE-1) != NULL) {
				if (header_line[0] == '\n' ||
				    header_line[0] == '\r' ||
				    header_line[0] == '\0') {
				  break;
				}
			}
		}

		/* enable SSL transport layer */
		if (stream) {
			if (php_stream_xport_crypto_setup(stream, STREAM_CRYPTO_METHOD_SSLv23_CLIENT, NULL TSRMLS_CC) < 0 ||
			    php_stream_xport_crypto_enable(stream, 1 TSRMLS_CC) < 0) {
				php_stream_wrapper_log_error(wrapper, options TSRMLS_CC, "Cannot connect to HTTPS server through proxy");
				php_stream_close(stream);
				stream = NULL;
			}
		}
	}

	php_stream_http_response_header_info_init(&header_info);

	if (stream == NULL)
		goto out;

	/* avoid buffering issues while reading header */
	if (options & STREAM_WILL_CAST)
		chunk_size = php_stream_set_chunk_size(stream, 1);

	/* avoid problems with auto-detecting when reading the headers -> the headers
	 * are always in canonical \r\n format */
	eol_detect = stream->flags & (PHP_STREAM_FLAG_DETECT_EOL | PHP_STREAM_FLAG_EOL_MAC);
	stream->flags &= ~(PHP_STREAM_FLAG_DETECT_EOL | PHP_STREAM_FLAG_EOL_MAC);

	php_stream_context_set(stream, context);

	php_stream_notify_info(context, PHP_STREAM_NOTIFY_CONNECT, NULL, 0);

	if (header_init && context && php_stream_context_get_option(context, "http", "max_redirects", &tmpzval) == SUCCESS) {
		SEPARATE_ZVAL(tmpzval);
		convert_to_long_ex(tmpzval);
		redirect_max = Z_LVAL_PP(tmpzval);
	}

	if (context && php_stream_context_get_option(context, "http", "method", &tmpzval) == SUCCESS) {
		if (Z_TYPE_PP(tmpzval) == IS_STRING && Z_STRLEN_PP(tmpzval) > 0) {
			/* As per the RFC, automatically redirected requests MUST NOT use other methods than
			 * GET and HEAD unless it can be confirmed by the user */
			if (!redirected
				|| (Z_STRLEN_PP(tmpzval) == 3 && memcmp("GET", Z_STRVAL_PP(tmpzval), 3) == 0)
				|| (Z_STRLEN_PP(tmpzval) == 4 && memcmp("HEAD",Z_STRVAL_PP(tmpzval), 4) == 0)
			) {
				scratch_len = strlen(path) + 29 + Z_STRLEN_PP(tmpzval);
				scratch = emalloc(scratch_len);
				strlcpy(scratch, Z_STRVAL_PP(tmpzval), Z_STRLEN_PP(tmpzval) + 1);
				strncat(scratch, " ", 1);
			}
		}
	}

	if (context && php_stream_context_get_option(context, "http", "protocol_version", &tmpzval) == SUCCESS) {
		SEPARATE_ZVAL(tmpzval);
		convert_to_double_ex(tmpzval);
		protocol_version_len = spprintf(&protocol_version, 0, "%.1F", Z_DVAL_PP(tmpzval));
	}

	if (!scratch) {
		scratch_len = strlen(path) + 29 + protocol_version_len;
		scratch = emalloc(scratch_len);
		strncpy(scratch, "GET ", scratch_len);
	}


	if (request_fulluri) {
		/* Ask for everything */
		strcat(scratch, path);
	} else {
		/* Send the traditional /path/to/file?query_string */

		/* file */
		if (resource->path && *resource->path) {
			strlcat(scratch, resource->path, scratch_len);
		} else {
			strlcat(scratch, "/", scratch_len);
		}

		/* query string */
		if (resource->query) {
			strlcat(scratch, "?", scratch_len);
			strlcat(scratch, resource->query, scratch_len);
		}
	}

	/* protocol version we are speaking */
	if (protocol_version) {
		strlcat(scratch, " HTTP/", scratch_len);
		strlcat(scratch, protocol_version, scratch_len);
		strlcat(scratch, "\r\n", scratch_len);
		efree(protocol_version);
		protocol_version = NULL;
	} else {
		strlcat(scratch, " HTTP/1.0\r\n", scratch_len);
	}

	/* send it */
	php_stream_write(stream, scratch, strlen(scratch));

	if (context && php_stream_context_get_option(context, "http", "header", &tmpzval) == SUCCESS) {
		tmp = NULL;

		if (Z_TYPE_PP(tmpzval) == IS_ARRAY) {
			HashPosition pos;
			zval **tmpheader = NULL;
			smart_str tmpstr = {0};

			for (zend_hash_internal_pointer_reset_ex(Z_ARRVAL_PP(tmpzval), &pos);
				SUCCESS == zend_hash_get_current_data_ex(Z_ARRVAL_PP(tmpzval), (void *)&tmpheader, &pos);
				zend_hash_move_forward_ex(Z_ARRVAL_PP(tmpzval), &pos)
			) {
				if (Z_TYPE_PP(tmpheader) == IS_STRING) {
					smart_str_appendl(&tmpstr, Z_STRVAL_PP(tmpheader), Z_STRLEN_PP(tmpheader));
					smart_str_appendl(&tmpstr, "\r\n", sizeof("\r\n") - 1);
				}
			}
			smart_str_0(&tmpstr);
			/* Remove newlines and spaces from start and end. there's at least one extra \r\n at the end that needs to go. */
			if (tmpstr.c) {
				tmp = php_trim(tmpstr.c, strlen(tmpstr.c), NULL, 0, NULL, 3 TSRMLS_CC);
				smart_str_free(&tmpstr);
			}
		}
		if (Z_TYPE_PP(tmpzval) == IS_STRING && Z_STRLEN_PP(tmpzval)) {
			/* Remove newlines and spaces from start and end php_trim will estrndup() */
			tmp = php_trim(Z_STRVAL_PP(tmpzval), Z_STRLEN_PP(tmpzval), NULL, 0, NULL, 3 TSRMLS_CC);
		}
		if (tmp && strlen(tmp) > 0) {
			char *s;

			user_headers = estrdup(tmp);

			/* Make lowercase for easy comparison against 'standard' headers */
			php_strtolower(tmp, strlen(tmp));

			if (!header_init) {
				/* strip POST headers on redirect */
				strip_header(user_headers, tmp, "content-length:");
				strip_header(user_headers, tmp, "content-type:");
			}

			if ((s = strstr(tmp, "user-agent:")) &&
			    (s == tmp || *(s-1) == '\r' || *(s-1) == '\n' ||
			                 *(s-1) == '\t' || *(s-1) == ' ')) {
				 have_header |= HTTP_HEADER_USER_AGENT;
			}
			if ((s = strstr(tmp, "host:")) &&
			    (s == tmp || *(s-1) == '\r' || *(s-1) == '\n' ||
			                 *(s-1) == '\t' || *(s-1) == ' ')) {
				 have_header |= HTTP_HEADER_HOST;
			}
			if ((s = strstr(tmp, "from:")) &&
			    (s == tmp || *(s-1) == '\r' || *(s-1) == '\n' ||
			                 *(s-1) == '\t' || *(s-1) == ' ')) {
				 have_header |= HTTP_HEADER_FROM;
				}
			if ((s = strstr(tmp, "authorization:")) &&
			    (s == tmp || *(s-1) == '\r' || *(s-1) == '\n' ||
			                 *(s-1) == '\t' || *(s-1) == ' ')) {
				 have_header |= HTTP_HEADER_AUTH;
			}
			if ((s = strstr(tmp, "content-length:")) &&
			    (s == tmp || *(s-1) == '\r' || *(s-1) == '\n' ||
			                 *(s-1) == '\t' || *(s-1) == ' ')) {
				 have_header |= HTTP_HEADER_CONTENT_LENGTH;
			}
			if ((s = strstr(tmp, "content-type:")) &&
			    (s == tmp || *(s-1) == '\r' || *(s-1) == '\n' ||
			                 *(s-1) == '\t' || *(s-1) == ' ')) {
				 have_header |= HTTP_HEADER_TYPE;
			}
			/* remove Proxy-Authorization header */
			if (use_proxy && use_ssl && (s = strstr(tmp, "proxy-authorization:")) &&
			    (s == tmp || *(s-1) == '\r' || *(s-1) == '\n' ||
			                 *(s-1) == '\t' || *(s-1) == ' ')) {
				char *p = s + sizeof("proxy-authorization:") - 1;

				while (s > tmp && (*(s-1) == ' ' || *(s-1) == '\t')) s--;
				while (*p != 0 && *p != '\r' && *p != '\n') p++;
				while (*p == '\r' || *p == '\n') p++;
				if (*p == 0) {
					if (s == tmp) {
						efree(user_headers);
						user_headers = NULL;
					} else {
						while (s > tmp && (*(s-1) == '\r' || *(s-1) == '\n')) s--;
						user_headers[s - tmp] = 0;
					}
				} else {
					memmove(user_headers + (s - tmp), user_headers + (p - tmp), strlen(p) + 1);
				}
			}

		}
		if (tmp) {
			efree(tmp);
		}
	}

	/* auth header if it was specified */
	if (((have_header & HTTP_HEADER_AUTH) == 0) && resource->user) {
		/* decode the strings first */
		php_url_decode(resource->user, strlen(resource->user));

		/* scratch is large enough, since it was made large enough for the whole URL */
		strcpy(scratch, resource->user);
		strcat(scratch, ":");

		/* Note: password is optional! */
		if (resource->pass) {
			php_url_decode(resource->pass, strlen(resource->pass));
			strcat(scratch, resource->pass);
		}

		tmp = (char*)php_base64_encode((unsigned char*)scratch, strlen(scratch), NULL);

		if (snprintf(scratch, scratch_len, "Authorization: Basic %s\r\n", tmp) > 0) {
			php_stream_write(stream, scratch, strlen(scratch));
			php_stream_notify_info(context, PHP_STREAM_NOTIFY_AUTH_REQUIRED, NULL, 0);
		}

		efree(tmp);
		tmp = NULL;
	}

	/* if the user has configured who they are, send a From: line */
	if (((have_header & HTTP_HEADER_FROM) == 0) && FG(from_address)) {
		if (snprintf(scratch, scratch_len, "From: %s\r\n", FG(from_address)) > 0)
			php_stream_write(stream, scratch, strlen(scratch));
	}

	/* Send Host: header so name-based virtual hosts work */
	if ((have_header & HTTP_HEADER_HOST) == 0) {
		if ((use_ssl && resource->port != 443 && resource->port != 0) ||
			(!use_ssl && resource->port != 80 && resource->port != 0)) {
			if (snprintf(scratch, scratch_len, "Host: %s:%i\r\n", resource->host, resource->port) > 0)
				php_stream_write(stream, scratch, strlen(scratch));
		} else {
			if (snprintf(scratch, scratch_len, "Host: %s\r\n", resource->host) > 0) {
				php_stream_write(stream, scratch, strlen(scratch));
			}
		}
	}

	if (context &&
	    php_stream_context_get_option(context, "http", "user_agent", &ua_zval) == SUCCESS &&
		Z_TYPE_PP(ua_zval) == IS_STRING) {
		ua_str = Z_STRVAL_PP(ua_zval);
	} else if (FG(user_agent)) {
		ua_str = FG(user_agent);
	}

	if (((have_header & HTTP_HEADER_USER_AGENT) == 0) && ua_str) {
#define _UA_HEADER "User-Agent: %s\r\n"
		char *ua;
		size_t ua_len;

		ua_len = sizeof(_UA_HEADER) + strlen(ua_str);

		/* ensure the header is only sent if user_agent is not blank */
		if (ua_len > sizeof(_UA_HEADER)) {
			ua = emalloc(ua_len + 1);
			if ((ua_len = slprintf(ua, ua_len, _UA_HEADER, ua_str)) > 0) {
				ua[ua_len] = 0;
				php_stream_write(stream, ua, ua_len);
			} else {
				php_error_docref(NULL TSRMLS_CC, E_WARNING, "Cannot construct User-agent header");
			}

			if (ua) {
				efree(ua);
			}
		}
	}

	if (user_headers) {
		/* A bit weird, but some servers require that Content-Length be sent prior to Content-Type for POST
		 * see bug #44603 for details. Since Content-Type maybe part of user's headers we need to do this check first.
		 */
		if (
				header_init &&
				context &&
				!(have_header & HTTP_HEADER_CONTENT_LENGTH) &&
				php_stream_context_get_option(context, "http", "content", &tmpzval) == SUCCESS &&
				Z_TYPE_PP(tmpzval) == IS_STRING && Z_STRLEN_PP(tmpzval) > 0
		) {
			scratch_len = slprintf(scratch, scratch_len, "Content-Length: %d\r\n", Z_STRLEN_PP(tmpzval));
			php_stream_write(stream, scratch, scratch_len);
			have_header |= HTTP_HEADER_CONTENT_LENGTH;
		}

		php_stream_write(stream, user_headers, strlen(user_headers));
		php_stream_write(stream, "\r\n", sizeof("\r\n")-1);
		efree(user_headers);
	}

	/* Request content, such as for POST requests */
	if (header_init && context &&
		php_stream_context_get_option(context, "http", "content", &tmpzval) == SUCCESS &&
		Z_TYPE_PP(tmpzval) == IS_STRING && Z_STRLEN_PP(tmpzval) > 0) {
		if (!(have_header & HTTP_HEADER_CONTENT_LENGTH)) {
			scratch_len = slprintf(scratch, scratch_len, "Content-Length: %d\r\n", Z_STRLEN_PP(tmpzval));
			php_stream_write(stream, scratch, scratch_len);
		}
		if (!(have_header & HTTP_HEADER_TYPE)) {
			php_stream_write(stream, "Content-Type: application/x-www-form-urlencoded\r\n",
				sizeof("Content-Type: application/x-www-form-urlencoded\r\n") - 1);
			php_error_docref(NULL TSRMLS_CC, E_NOTICE, "Content-type not specified assuming application/x-www-form-urlencoded");
		}
		php_stream_write(stream, "\r\n", sizeof("\r\n")-1);
		php_stream_write(stream, Z_STRVAL_PP(tmpzval), Z_STRLEN_PP(tmpzval));
	} else {
		php_stream_write(stream, "\r\n", sizeof("\r\n")-1);
	}

	if (!EG(active_symbol_table)) {
		zend_rebuild_symbol_table(TSRMLS_C);
	}

	if (header_init) {
		zval *ztmp;
		MAKE_STD_ZVAL(ztmp);
		array_init(ztmp);
		ZEND_SET_SYMBOL(EG(active_symbol_table), "http_response_header", ztmp);
	}

	{
		zval **rh;
		if(zend_hash_find(EG(active_symbol_table), "http_response_header", sizeof("http_response_header"), (void **) &rh) != SUCCESS || Z_TYPE_PP(rh) != IS_ARRAY) {
			php_stream_wrapper_log_error(wrapper, options TSRMLS_CC, "HTTP request failed, http_response_header overwritten");
			goto out;
		}
		response_header = *rh;
		Z_ADDREF_P(response_header);
	}

	if (!php_stream_eof(stream)) {
		size_t tmp_line_len;
		/* get response header */

		if (php_stream_get_line(stream, tmp_line, sizeof(tmp_line) - 1, &tmp_line_len) != NULL) {
			zval *http_response;

			if (tmp_line_len > 9) {
				response_code = atoi(tmp_line + 9);
			} else {
				response_code = 0;
			}
			if (context && SUCCESS==php_stream_context_get_option(context, "http", "ignore_errors", &tmpzval)) {
				ignore_errors = zend_is_true(*tmpzval);
			}
			/* when we request only the header, don't fail even on error codes */
			if ((options & STREAM_ONLY_GET_HEADERS) || ignore_errors) {
				reqok = 1;
			}
			/* all status codes in the 2xx range are defined by the specification as successful;
			 * all status codes in the 3xx range are for redirection, and so also should never
			 * fail */
			if (response_code >= 200 && response_code < 400) {
				reqok = 1;
			} else {
				switch(response_code) {
					case 403:
						php_stream_notify_error(context, PHP_STREAM_NOTIFY_AUTH_RESULT,
								tmp_line, response_code);
						break;
					default:
						/* safety net in the event tmp_line == NULL */
						if (!tmp_line_len) {
							tmp_line[0] = '\0';
						}
						php_stream_notify_error(context, PHP_STREAM_NOTIFY_FAILURE,
								tmp_line, response_code);
				}
			}
			if (tmp_line_len >= 1 && tmp_line[tmp_line_len - 1] == '\n') {
				--tmp_line_len;
				if (tmp_line_len >= 1 &&tmp_line[tmp_line_len - 1] == '\r') {
					--tmp_line_len;
				}
			}
			MAKE_STD_ZVAL(http_response);
			ZVAL_STRINGL(http_response, tmp_line, tmp_line_len, 1);
			zend_hash_next_index_insert(Z_ARRVAL_P(response_header), &http_response, sizeof(zval *), NULL);
		}
	} else {
		php_stream_wrapper_log_error(wrapper, options TSRMLS_CC, "HTTP request failed, unexpected end of socket!");
		goto out;
	}

	/* read past HTTP headers */
	while (!php_stream_eof(stream)) {
		size_t http_header_line_length;
		if (http_header_line != NULL) {
			efree(http_header_line);
		}

		if ((http_header_line = php_stream_get_line(stream, NULL, 0, &http_header_line_length))) {
			zend_bool last_line;
			if (*http_header_line == '\r') {
				if (http_header_line[1] != '\n') {
					php_stream_close(stream);
					stream = NULL;
					php_stream_wrapper_log_error(wrapper, options,
							"HTTP invalid header name (cannot start with CR character)!");
					goto out;
				}
				last_line = 1;
			} else if (*http_header_line == '\n') {
				last_line = 1;
			} else {
				last_line = 0;
			}

			if (last_header_line_str != NULL) {
				/* Parse last header line. */
				last_header_line_str = php_stream_http_response_headers_parse(wrapper, stream,
						context, options, last_header_line_str, http_header_line,
						&http_header_line_length, response_code, response_header, &header_info,
						&last_header_line_length);
				if (EXPECTED(last_header_line_str == NULL)) {
					if (UNEXPECTED(header_info.error)) {
						php_stream_close(stream);
						stream = NULL;
						goto out;
					}
				} else {
					/* Folding header present so continue. */
					continue;
				}
			} else if (!last_line) {
				/* The first line cannot start with spaces. */
				if (*http_header_line == ' ' || *http_header_line == '\t') {
					php_stream_close(stream);
					stream = NULL;
					php_stream_wrapper_log_error(wrapper, options,
							"HTTP invalid response format (folding header at the start)!");
					goto out;
				}
				/* Trim the first line if it is not the last line. */
				php_stream_http_response_header_trim(http_header_line, &http_header_line_length);
			}

			if (last_line) {
				/* For the last line the last header line must be NULL. */
				if (last_header_line_str != NULL)
					return NULL;
				break;
			}
			/* Save current line as the last line so it gets parsed in the next round. */
			last_header_line_str = estrndup(http_header_line, http_header_line_length);
			last_header_line_length = http_header_line_length;
		} else {
			break;
		}
	}

	/* If the stream was closed early, we still want to process the last line to keep BC. */
	if (last_header_line_str != NULL) {
		php_stream_http_response_headers_parse(wrapper, stream, context, options,
		last_header_line_str, NULL, NULL, response_code, response_header, &header_info,
		&last_header_line_length);
	}

	if (!reqok || (header_info.location != NULL && header_info.follow_location)) {
		if (!header_info.follow_location || (((options & STREAM_ONLY_GET_HEADERS) || ignore_errors) && redirect_max <= 1)) {
			goto out;
		}

		if (header_info.location != NULL)
			php_stream_notify_info(context, PHP_STREAM_NOTIFY_REDIRECTED, header_info.location, 0);

		php_stream_close(stream);
		stream = NULL;

		if (header_info.transfer_encoding) {
			php_stream_filter_free(header_info.transfer_encoding);
			header_info.transfer_encoding = NULL;
		}

		if (header_info.location != NULL) {

			char *new_path = NULL;

			if (strlen(header_info.location) < 8 ||
					(strncasecmp(header_info.location, "http://", sizeof("http://")-1) &&
							strncasecmp(header_info.location, "https://", sizeof("https://")-1) &&
							strncasecmp(header_info.location, "ftp://", sizeof("ftp://")-1) &&
							strncasecmp(header_info.location, "ftps://", sizeof("ftps://")-1)))
			{
				char *loc_path = NULL;
				if (*header_info.location != '/') {
					if (*(header_info.location+1) != '\0' && resource->path) {
						char *s = strrchr(resource->path, '/');
						if (!s) {
							s = resource->path;
							if (!s[0]) {
								efree(s);
								s = resource->path = estrdup("/");
							} else {
								*s = '/';
							}
						}
						s[1] = '\0';
						if (resource->path && *(resource->path) == '/' && *(resource->path + 1) == '\0') {
							spprintf(&loc_path, 0, "%s%s", resource->path, header_info.location);
						} else {
							spprintf(&loc_path, 0, "%s/%s", resource->path, header_info.location);
						}
					} else {
						spprintf(&loc_path, 0, "/%s", header_info.location);
					}
				} else {
					loc_path = header_info.location;
					header_info.location = NULL;
				}
				if ((use_ssl && resource->port != 443) || (!use_ssl && resource->port != 80)) {
					spprintf(&new_path, 0, "%s://%s:%d%s", resource->scheme,
							resource->host, resource->port, loc_path);
				} else {
					spprintf(&new_path, 0, "%s://%s%s", resource->scheme,
							resource->host, loc_path);
				}
				efree(loc_path);
			} else {
				new_path = header_info.location;
				header_info.location = NULL;
			}

			php_url_free(resource);
			/* check for invalid redirection URLs */
			if ((resource = php_url_parse(new_path)) == NULL) {
				php_stream_wrapper_log_error(wrapper, options TSRMLS_CC, "Invalid redirect URL! %s", new_path);
				efree(new_path);
				goto out;
			}

#define CHECK_FOR_CNTRL_CHARS(val) { \
	if (val) { \
		unsigned char *s, *e; \
		int l; \
		l = php_url_decode(val, strlen(val)); \
		s = (unsigned char*)val; e = s + l; \
		while (s < e) { \
			if (iscntrl(*s)) { \
				php_stream_wrapper_log_error(wrapper, options TSRMLS_CC, "Invalid redirect URL! %s", new_path); \
				efree(new_path); \
				goto out; \
			} \
			s++; \
		} \
	} \
}
			/* check for control characters in login, password & path */
			if (strncasecmp(new_path, "http://", sizeof("http://") - 1) || strncasecmp(new_path, "https://", sizeof("https://") - 1)) {
				CHECK_FOR_CNTRL_CHARS(resource->user)
				CHECK_FOR_CNTRL_CHARS(resource->pass)
				CHECK_FOR_CNTRL_CHARS(resource->path)
			}
			stream = php_stream_url_wrap_http_ex(wrapper, new_path, mode, options, opened_path, context, --redirect_max, HTTP_WRAPPER_REDIRECTED STREAMS_CC TSRMLS_CC);
			efree(new_path);
		} else {
			php_stream_wrapper_log_error(wrapper, options TSRMLS_CC, "HTTP request failed! %s", tmp_line);
		}
	}
out:
	if (protocol_version) {
		efree(protocol_version);
	}

	if (http_header_line) {
		efree(http_header_line);
	}

	if (scratch) {
		efree(scratch);
	}

	if (header_info.location != NULL) {
		efree(header_info.location);
	}

	if (resource) {
		php_url_free(resource);
	}

	if (stream) {
		if (header_init) {
			stream->wrapperdata = response_header;
		} else {
			if(response_header) {
				Z_DELREF_P(response_header);
			}
		}
		php_stream_notify_progress_init(context, 0, header_info.file_size);

		/* Restore original chunk size now that we're done with headers */
		if (options & STREAM_WILL_CAST)
			php_stream_set_chunk_size(stream, chunk_size);

		/* restore the users auto-detect-line-endings setting */
		stream->flags |= eol_detect;

		/* as far as streams are concerned, we are now at the start of
		 * the stream */
		stream->position = 0;

		/* restore mode */
		strlcpy(stream->mode, mode, sizeof(stream->mode));

		if (header_info.transfer_encoding) {
			php_stream_filter_append(&stream->readfilters, header_info.transfer_encoding);
		}
	} else {
		if(response_header) {
			Z_DELREF_P(response_header);
		}
		if (header_info.transfer_encoding) {
			php_stream_filter_free(header_info.transfer_encoding TSRMLS_CC);
		}
	}

	return stream;
}
/* }}} */

php_stream *php_stream_url_wrap_http(php_stream_wrapper *wrapper, char *path, char *mode, int options, char **opened_path, php_stream_context *context STREAMS_DC TSRMLS_DC) /* {{{ */
{
	return php_stream_url_wrap_http_ex(wrapper, path, mode, options, opened_path, context, PHP_URL_REDIRECT_MAX, HTTP_WRAPPER_HEADER_INIT STREAMS_CC TSRMLS_CC);
}
/* }}} */

static int php_stream_http_stream_stat(php_stream_wrapper *wrapper, php_stream *stream, php_stream_statbuf *ssb TSRMLS_DC) /* {{{ */
{
	/* one day, we could fill in the details based on Date: and Content-Length:
	 * headers.  For now, we return with a failure code to prevent the underlying
	 * file's details from being used instead. */
	return -1;
}
/* }}} */

static php_stream_wrapper_ops http_stream_wops = {
	php_stream_url_wrap_http,
	NULL, /* stream_close */
	php_stream_http_stream_stat,
	NULL, /* stat_url */
	NULL, /* opendir */
	"http",
	NULL, /* unlink */
	NULL, /* rename */
	NULL, /* mkdir */
	NULL  /* rmdir */
};

PHPAPI php_stream_wrapper php_stream_http_wrapper = {
	&http_stream_wops,
	NULL,
	1 /* is_url */
};

/*
 * Local variables:
 * tab-width: 4
 * c-basic-offset: 4
 * End:
 * vim600: sw=4 ts=4 fdm=marker
 * vim<600: sw=4 ts=4
 */
