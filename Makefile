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
# Image the unit tests run in. Any Nextcloud image works: it has PHP with GD.
php_image=docker.io/library/nextcloud:34-apache
# Container the integration tests run in; override with `make tests nc_container=...`
nc_container=nc35

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

# Build the rs-fallback binary only when sources are newer than the binary.
# `find` lists every file under rs-fallback/ so any change triggers a rebuild.
rs_fallback_sources := $(shell find rs-fallback -type f)
bin/rs-fallback-linux-x86_64: $(rs_fallback_sources)
	bash rs-fallback/build.sh x86_64

# Builds the source package for the app store, ignores php and js tests
.PHONY: appstore
appstore: bin/rs-fallback-linux-x86_64
	rm -rf $(appstore_build_directory)
	mkdir -p $(appstore_build_directory)/$(app_name)
	# Copy only files tracked by git — nothing untracked or gitignored ends up
	# in the release tarball. vendor/ is not in git but must ship with the app,
	# so it is copied in explicitly afterwards.
	# Copy git-tracked files + vendor/ + bin/ into the staging directory.
	git ls-files | grep -v '^rs-fallback/' | grep -v '^\.' | tar -c --files-from=- | tar -x -C $(appstore_build_directory)/$(app_name)
	cp -r $(vendor_directory) $(appstore_build_directory)/$(app_name)/vendor
	cp bin/rs-fallback-linux-* $(appstore_build_directory)/$(app_name)/bin/

	docker run --rm -v $(appstore_build_directory)/$(app_name):/$(app_name):z -v ~/.nextcloud/certificates:/certs:z nextcloud:33-apache php /usr/src/nextcloud/occ integrity:sign-app --path=/$(app_name) --privateKey="/certs/camerarawpreviews.key" --certificate="/certs/camerarawpreviews.crt"
	tar -czf build/$(app_name)_nextcloud.tar.gz -C "$(appstore_build_directory)" $(app_name)
	openssl dgst -sha512 -sign ~/.nextcloud/certificates/camerarawpreviews.key build/camerarawpreviews_nextcloud.tar.gz | openssl base64

# Runs the dependency-free unit tests (no Nextcloud instance needed) in a
# throwaway container, so the host needs neither PHP nor GD. The app is mounted
# read-only; --do-not-cache-result keeps phpunit from writing into it.
.PHONY: unit-tests
unit-tests: $(phpunit_phar)
	$(container_runtime) run --rm -v $(CURDIR):/$(app_name):ro,z --workdir /$(app_name) $(php_image) php build/tools/phpunit.phar -c phpunit.xml --do-not-cache-result

$(phpunit_phar):
	@echo "Downloading PHPUnit $(phpunit_version) from the web"
	mkdir -p $(build_tools_directory)
	curl -sSL https://phar.phpunit.de/phpunit-$(phpunit_version).phar -o $(phpunit_phar)

# Runs the whole test suite, including the Nextcloud integration tests, inside
# the Nextcloud container that has the app mounted in custom_apps (see
# ../nextcloud/compose.yml). The container is started if it is stopped. The
# image ships no phpunit, so the phar from build/tools is used through the
# bind mount.
.PHONY: tests
tests: $(phpunit_phar)
	$(container_runtime) start $(nc_container) > /dev/null
	$(container_runtime) exec --workdir /var/www/html/custom_apps/$(app_name) --user www-data $(nc_container) php build/tools/phpunit.phar --no-configuration --do-not-cache-result --stop-on-failure --bootstrap tests/bootstrap.php tests/
