#!/bin/sh
# SPDX-FileCopyrightText: 2026 LibreSign contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
set -eu

# Compose secrets do not appear in docker inspect's application environment.
if [ -n "${APP_SECRET_FILE:-}" ]; then
    if [ ! -r "$APP_SECRET_FILE" ]; then
        echo "APP_SECRET_FILE is not readable" >&2
        exit 1
    fi
    APP_SECRET="$(cat "$APP_SECRET_FILE")"
    export APP_SECRET
fi

if [ -z "${APP_SECRET:-}" ]; then
    echo "APP_SECRET is required" >&2
    exit 1
fi

exec "$@"
