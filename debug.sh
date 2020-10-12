#! /usr/bin/env bash
curl -s -X POST -H "Content-Type: application/json" -H "Accept: application/json" -H "Travis-API-Version: 3" -H "Authorization: token q3IYSHdwN_XsA7FcFvJxsw" -d '{ "quiet": true }' https://api.travis-ci.com/job/$1/debug
