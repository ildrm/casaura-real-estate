#!/usr/bin/env sh
set -eu

if rg --hidden \
  --glob '!**/.git/**' \
  --glob '!**/node_modules/**' \
  --glob '!**/vendor/**' \
  --glob '!**/*lock*' \
  --glob '!**/*.example' \
  '(-----BEGIN (RSA |EC |OPENSSH )?PRIVATE KEY-----|AKIA[0-9A-Z]{16}|gh[pousr]_[A-Za-z0-9_]{30,}|xox[baprs]-[A-Za-z0-9-]{20,})' .; then
  echo 'Potential committed secret detected.' >&2
  exit 1
fi

echo 'No high-confidence committed secret patterns detected.'
