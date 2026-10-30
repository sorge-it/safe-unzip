# Records the demo of the README and the social preview in Docker: no local
# PHP and no VHS needed. `make -C demo all` from the root of the package.

.DEFAULT_GOAL := help

ROOT  := $(abspath $(CURDIR)/..)
IMAGE := safe-unzip-demo
# As the user of the host: files the container writes stay the user's on Linux too.
RUN   := docker run --rm --cpus 2 --user $(shell id -u):$(shell id -g) -e HOME=/tmp -v "$(ROOT):/app" -w /app/demo/app $(IMAGE)

.PHONY: help build install preflight gif png all clean

help: ## Show the targets
	@grep -E '^[a-z]+:.*## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*## "}; {printf "  %-10s %s\n", $$1, $$2}'

build: ## Build the image: VHS with PHP, ext-zip, Info-ZIP and Composer
	docker build -q -t $(IMAGE) $(CURDIR)

install: build ## Install the demo app with the package from this checkout, and build its archives
	$(RUN) sh -c 'rm -rf vendor composer.lock out-* && composer install --no-interaction --no-progress -q && php archives.php'

preflight: install ## Check that every scene shows what the recording expects
	$(RUN) sh -c 'php ext-zip.php tool.zip out-check-1 && stat -c %A out-check-1/bin/tool | grep -qx -- -rw-r--r--'
	$(RUN) sh -c 'php safe-unzip.php tool.zip out-check-2 && stat -c %A out-check-2/bin/tool | grep -qx -- -rwxr-xr-x'
	$(RUN) sh -c 'php ext-zip.php overlap.zip out-check-3 && du -sm out-check-3 | grep -q "^20[0-9]"'
	$(RUN) sh -c '! php safe-unzip.php overlap.zip out-check-4 2> out-check-errors && grep -q "Entries overlap" out-check-errors && test ! -e out-check-4'
	$(RUN) sh -c 'rm -rf out-*'

gif: preflight ## Record output/demo.gif
	mkdir -p output
	$(RUN) vhs ../demo.tape

png: preflight ## Record output/social.png
	mkdir -p output
	$(RUN) vhs ../social.tape

all: gif png ## Record both

clean: ## Remove the installed demo app and its archives
	rm -rf app/vendor app/composer.lock app/*.zip app/out-*
