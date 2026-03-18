#!/usr/bin/env sh
#
# Configures this repository to use the project's shared Git hooks
# and commit message template.
#
# Run once after cloning: sh scripts/setup-hooks.sh
#

set -e

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"

echo "Setting up Git hooks..."
git -C "$REPO_ROOT" config core.hooksPath .githooks
echo "  core.hooksPath → .githooks"

echo "Setting commit message template..."
git -C "$REPO_ROOT" config commit.template .gitmessage
echo "  commit.template → .gitmessage"

echo ""
echo "Done. Commit messages will now be validated against Conventional Commits."
