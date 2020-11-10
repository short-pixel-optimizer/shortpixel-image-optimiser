#! /usr/bin/env bash

if [ -z $1 ]; then
    echo "First argument must be the job id found in the URL of a build at https://travis-ci.com/github/short-pixel-optimizer/shortpixel-image-optimiser/builds"
    exit 1
fi

source .env

curl -s -X POST -H "Content-Type: application/json" -H "Accept: application/json" -H "Travis-API-Version: 3" -H "Authorization: token $TRAVIS_DEBUG_TOKEN" -d '{ "quiet": true }' https://api.travis-ci.com/job/$1/debug