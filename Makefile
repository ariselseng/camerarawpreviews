# This file is licensed under the Affero General Public License version 3 or
# later. See the COPYING file.
# @author Bernhard Posselt <dev@bernhard-posselt.com>
# @copyright Bernhard Posselt 2016

# Generic Makefile for building and packaging a Nextcloud app which uses npm and
# Composer.
#
# Dependencies:
# * make
# * which
# * curl: used if phpunit and composer are not installed to fetch them from the web
# * tar: for building the archive
# * npm: for building and testing everything JS
#
# If no composer.json is in the app root directory, the Composer step
# will be skipped. The same goes for the package.json which can be located in
# the app root or the js/ directory.
#
# The npm command by launches the npm build script:
#
#    npm run build
#
# The npm test command launches the npm test script:
#
#    npm run test
#
# The idea behind this is to be completely testing and build tool agnostic. All
# build tools and additional package managers should be installed locally in
# your project, since this won't pollute people's global namespace.
#
# The following npm scripts in your package.json install and update the bower
# and npm dependencies and use gulp as build system (notice how everything is
# run from the node_modules folder):
#
#    "scripts": {
#        "test": "node node_modules/gulp-cli/bin/gulp.js karma",
#        "prebuild": "npm install && node_modules/bower/bin/bower install && node_modules/bower/bin/bower update",
#        "build": "node node_modules/gulp-cli/bin/gulp.js"
#    },

app_name=camerarawpreviews
build_tools_directory=$(CURDIR)/build/tools
vendor_directory=$(CURDIR)/vendor
source_build_directory=$(CURDIR)/build/artifacts/source
source_package_name=$(source_build_directory)/$(app_name)
appstore_build_directory=$(CURDIR)/build/artifacts/appstore
appstore_package_name=$(appstore_build_directory)/$(app_name)
composer=$(shell which composer 2> /dev/null)
phpunit_version=11
phpunit_phar=$(build_tools_directory)/phpunit.phar
container_runtime=$(shell which docker 2> /dev/null || which podman 2> /dev/null)
# Image all test suites run in, built from tests/Containerfile
test_image=$(app_name)-test
# html volume of the Nextcloud instance the integration tests run against;
# with ../nextcloud/compose.yml use `make tests nc_volume=nextcloud_nc35_html`
nc_volume=nc35_html
# `run` arguments for a test container that has the Nextcloud instance from
# nc_volume and the app mounted where the instance expects it, as www-data.
# The RAW samples the integration tests download are kept in a volume.
nc_run_args=-v $(nc_volume):/var/www/html -v $(app_name)-test-assets:/var/cache/camerarawpreviews-test -v $(CURDIR):/var/www/html/custom_apps/$(app_name):z --workdir /var/www/html/custom_apps/$(app_name) --user www-data
nc_phpunit=php build/tools/phpunit.phar --no-configuration --do-not-cache-result --bootstrap tests/bootstrap.php

all: build

# Fetches the PHP and JS dependencies and compiles the JS. If no composer.json
# is present, the composer step is skipped, if no package.json or js/package.json
# is present, the npm step is skipped
.PHONY: build
build:
ifneq (,$(wildcard $(CURDIR)/composer.json))
	make composer
endif
ifneq (,$(wildcard $(CURDIR)/package.json))
	make npm
endif

# Installs and updates the composer dependencies. If composer is not installed
# a copy is fetched from the web
.PHONY: composer
composer:
ifeq (, $(composer))
	@echo "No composer command available, downloading a copy from the web"
	mkdir -p $(build_tools_directory)
	curl -sS https://getcomposer.org/installer | php
	mv composer.phar $(build_tools_directory)
	php $(build_tools_directory)/composer.phar install --prefer-dist
	php $(build_tools_directory)/composer.phar update --prefer-dist
else
	composer install --prefer-dist
	composer update --prefer-dist
endif

# Removes the appstore build
.PHONY: clean
clean:
	rm -rf ./build/artifacts
	rm -rf ./build/camerarawpreviews*tar.gz

# Builds the source and appstore package
.PHONY: dist
dist:
	composer install --prefer-dist
	make tests
	make appstore

# Builds the source package
.PHONY: perl
perl:
	$(build_tools_directory)/perl-build/build.sh

# Builds the source package
.PHONY: source
source:
	rm -rf $(source_build_directory)
	mkdir -p $(source_build_directory)
	tar cvzf $(source_package_name).tar.gz ../$(app_name) \
	--exclude-vcs \
	--exclude="../$(app_name)/build" \
	--exclude="../$(app_name)/js/node_modules" \
	--exclude="../$(app_name)/node_modules" \
	--exclude="../$(app_name)/*.log" \
	--exclude="../$(app_name)/js/*.log" \

# Build the bundled rs-fallback binary for one architecture only when sources
# are newer than the binary. Every file under rs-fallback/ is a dependency, so
# any Rust change triggers a rebuild.
rs_fallback_sources := $(shell find rs-fallback -type f -not -path 'rs-fallback/target/*')
bin/rs-fallback-linux-%: $(rs_fallback_sources)
	bash rs-fallback/build.sh $*

# Architectures that `make appstore` requires. Set it to ship both:
#   make appstore rs_fallback_archs="x86_64 aarch64"
# aarch64 needs QEMU binfmt on an x86_64 host, or an aarch64 host; see
# rs-fallback/build.sh.
rs_fallback_archs ?= x86_64
rs_fallback_bins := $(addprefix bin/rs-fallback-linux-,$(rs_fallback_archs))

# Builds both architectures explicitly. `make appstore` copies every
# bin/rs-fallback-linux-* that exists, so this is enough to ship arm64.
.PHONY: rs-fallback
rs-fallback: bin/rs-fallback-linux-x86_64 bin/rs-fallback-linux-aarch64

# Builds the source package for the app store, ignores php and js tests
.PHONY: appstore
appstore: $(rs_fallback_bins)
	rm -rf $(appstore_build_directory)
	mkdir -p $(appstore_build_directory)/$(app_name)
	# Copy only files tracked by git — nothing untracked or gitignored ends up
	# in the release tarball. vendor/ is not in git but must ship with the app,
	# so it is copied in explicitly afterwards.
	# Copy git-tracked files + vendor/ + bin/ into the staging directory.
	git ls-files | grep -v '^rs-fallback/' | grep -v '^\.' | tar -c --files-from=- | tar -x -C $(appstore_build_directory)/$(app_name)
	cp -r $(vendor_directory) $(appstore_build_directory)/$(app_name)/vendor
	cp bin/rs-fallback-linux-* $(appstore_build_directory)/$(app_name)/bin/

	$(container_runtime) run --rm -v $(appstore_build_directory)/$(app_name):/$(app_name):z -v ~/.nextcloud/certificates:/certs:z nextcloud:33-apache php /usr/src/nextcloud/occ integrity:sign-app --path=/$(app_name) --privateKey="/certs/camerarawpreviews.key" --certificate="/certs/camerarawpreviews.crt"
	tar -czf build/$(app_name)_nextcloud.tar.gz -C "$(appstore_build_directory)" $(app_name)
	openssl dgst -sha512 -sign ~/.nextcloud/certificates/camerarawpreviews.key build/camerarawpreviews_nextcloud.tar.gz | openssl base64

# Runs the dependency-free unit tests (no Nextcloud instance needed), so the
# host needs neither PHP nor GD. The app is mounted read-only;
# --do-not-cache-result keeps phpunit from writing into it.
.PHONY: unit-tests
unit-tests: $(phpunit_phar) test-image nc-volume
	$(container_runtime) run --rm -v $(CURDIR):/$(app_name):ro,z --workdir /$(app_name) $(test_image) php build/tools/phpunit.phar -c phpunit.xml --do-not-cache-result

# Audits the Rust and PHP dependency trees against their advisory databases.
# Requires cargo-audit (`cargo install cargo-audit`) and composer.
.PHONY: audit
audit:
	cd rs-fallback && cargo audit
	composer audit --locked

# Runs the whole test suite, including the Nextcloud integration tests, against
# the Nextcloud instance in nc_volume. The instance does not need to be running.
.PHONY: tests
tests: $(phpunit_phar) test-image nc-volume
	$(container_runtime) run --rm $(nc_run_args) $(test_image) $(nc_phpunit) --stop-on-failure tests/

# Runs the whole test suite like `tests` and reports line coverage of lib/: a
# summary per class in the terminal and an HTML report in build/coverage. The
# report is written as www-data inside the container and copied out afterwards,
# so the files on the host belong to you.
.PHONY: coverage
coverage: $(phpunit_phar) test-image nc-volume
	rm -rf build/coverage
	$(container_runtime) rm -f $(app_name)-coverage > /dev/null
	$(container_runtime) run --name $(app_name)-coverage $(nc_run_args) $(test_image) $(nc_phpunit) --coverage-filter lib --coverage-text --coverage-html /tmp/coverage tests/; \
	status=$$?; \
	$(container_runtime) cp $(app_name)-coverage:/tmp/coverage build/coverage; \
	$(container_runtime) rm $(app_name)-coverage > /dev/null; \
	exit $$status

# Fails early when nc_volume does not exist; `run -v` would otherwise create
# it empty without a word
.PHONY: nc-volume
nc-volume:
	@$(container_runtime) volume inspect $(nc_volume) > /dev/null 2>&1 || \
		{ echo "Volume $(nc_volume) not found; set nc_volume to the html volume of a Nextcloud instance" >&2; exit 1; }

# Builds the test image; a no-op when tests/Containerfile is unchanged
.PHONY: test-image
test-image:
	$(container_runtime) build -q -t $(test_image) -f tests/Containerfile tests > /dev/null

$(phpunit_phar):
	@echo "Downloading PHPUnit $(phpunit_version) from the web"
	mkdir -p $(build_tools_directory)
	curl -fsSL https://phar.phpunit.de/phpunit-$(phpunit_version).phar -o $(phpunit_phar).tmp
	mv $(phpunit_phar).tmp $(phpunit_phar)
