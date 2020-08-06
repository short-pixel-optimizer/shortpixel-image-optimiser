#!/usr/bin/env bash

#vendor/bin/codecept run acceptance
vendor/bin/codecept run functional
#vendor/bin/codecept run unit
#  - vendor/bin/codecept run wpunit TODO Needs fixing. "Test" db install, "PHP Warning:  Cannot modify header information - headers already sent by"

while [[ "$#" -gt 0 ]]; do
    case $1 in
        -t|--test) test="$2"; shift ;;
        -u|--uglify) uglify=1 ;;
        *) echo "Unknown parameter passed: $1"; exit 1 ;;
    esac
    shift
done

echo 'Test' "$test"

if [ -n  "$test" ]
then
	echo ./vendor/bin/phpunit --verbose --testsuite "$test"
	 ./vendor/bin/phpunit --verbose --testsuite "$test"
else
	./vendor/bin/phpunit --verbose
fi
