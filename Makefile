# Portable Makefile (FreeBSD BSD make & GNU make).

PHP ?= php
JOBS ?= 4

.PHONY: check lint test test-unit test-web test-mariadb clean-tests

check: lint test

lint:
	@find engine infra/runtime modules public tests -type f -name '*.php' -print0 | xargs -0 -n1 $(PHP) -l >/dev/null
	@echo "PHP lint OK"