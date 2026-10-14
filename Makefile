app_name  = backupdav
build_dir = $(CURDIR)/build
stage_dir = $(build_dir)/$(app_name)
sources   = appinfo lib README.md LICENSE

# Unit tests run in the Nextcloud release the app targets: OCP and sabre/dav come from it.
NC_IMAGE   ?= nextcloud:35.0.1-apache
docker_run  = docker run --rm -u $(shell id -u):$(shell id -g) -v $(CURDIR):/app -w /app

.PHONY: all build clean test integration

all: build

build: clean
	mkdir -p $(stage_dir)
	cp -r $(sources) $(stage_dir)/
	tar -czf $(build_dir)/$(app_name).tar.gz -C $(build_dir) $(app_name)
	@echo "Built: $(build_dir)/$(app_name).tar.gz"

clean:
	rm -rf $(build_dir)

vendor: composer.json
	$(docker_run) -e COMPOSER_HOME=/tmp/composer composer:2 install --no-interaction --no-progress
	touch vendor

test: vendor
	$(docker_run) --entrypoint php $(NC_IMAGE) vendor/bin/phpunit

integration:
	tests/integration/run.sh
