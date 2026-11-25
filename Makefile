# Portable Makefile (FreeBSD BSD make & GNU make).

PHP ?= php
JOBS ?= 4

.PHONY: check lint test test-unit test-web test-mariadb clean-tests

check: lint test

lint:
	@find bootstrap.php engine infra/runtime modules public tests -type f -name '*.php' -print0 | xargs -0 -n1 $(PHP) -l >/dev/null
	@echo "PHP lint OK"

test:
	$(PHP) tests/run-tests.php -j $(JOBS)

test-unit:
	$(PHP) tests/run-tests.php -j $(JOBS) engine modules infra/runtime

test-web:
	$(PHP) tests/run-tests.php -j 1 tests/web.phpt

test-mariadb:
	$(PHP) tests/run-tests.php -j 1 tests/mariadb.phpt

clean-tests:
	@find engine modules infra tests -type f \( -name '*.out' -o -name '*.exp' -o -name '*.diff' -o -name '*.skip.php' -o -name '*.clean.php' \) -delete
