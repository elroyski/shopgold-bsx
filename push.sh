#!/bin/bash
# Skrypt do wysyłania zmian do GitHub

set -e

git add -A
git commit -m "${1:-update}"
git push -u origin main
