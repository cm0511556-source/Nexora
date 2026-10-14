#!/usr/bin/env sh
# Nexora Logistics — start the local PHP built-in server.
#
#   Public site: http://localhost:8000/
#   Admin:       http://localhost:8000/admin/login.php
#
# IMPORTANT: public/ is the ONLY document root. Never start the server from the
# project root (`php -S localhost:8000`) — that would expose .env, .git and the
# SQLite database to the network. Always use -t public.
exec php -S localhost:8000 -t public
