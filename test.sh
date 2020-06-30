#!/usr/bin/env bash

vendor/bin/codecept run acceptance
vendor/bin/codecept run functional
vendor/bin/codecept run unit
#  - vendor/bin/codecept run wpunit TODO Needs fixing. "Test" db install, "PHP Warning:  Cannot modify header information - headers already sent by"
#./vendor/bin/phpunit --verbose
