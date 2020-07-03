#!/usr/bin/env bash

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

