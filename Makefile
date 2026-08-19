# Developer convenience targets. The interesting pair is bundle-link / bundle-release:
# uhifadhilabs bundles are developed as sibling checkouts (../<bundle>) and consumed
# here as composer (vcs, dev-main) dependencies. During development you want the local
# checkout wired in so edits show up instantly; before committing you want the PUBLISHED
# remote so composer.lock + CI reflect reality. These targets toggle the two, for any
# bundle, so you never hand-symlink again.
#
#   make bundle-link    BUNDLE=uhakiki-bundle   # local checkout (fast dev)
#   make bundle-release BUNDLE=uhakiki-bundle   # drop the link, pull the pushed remote, verify
#
# Release flow: commit + push the bundle (watch its CI to green) → `make bundle-release`.

BUNDLE ?= uhakiki-bundle
VENDOR := vendor/uhifadhilabs/$(BUNDLE)
LOCAL  := $(abspath ../$(BUNDLE))

.PHONY: bundle-link bundle-release check

## Symlink the local sibling checkout into vendor/ (edits are live; NOT for commit).
bundle-link:
	@test -d "$(LOCAL)" || { echo "No local checkout at $(LOCAL)"; exit 1; }
	rm -rf "$(VENDOR)"
	ln -s "$(LOCAL)" "$(VENDOR)"
	@echo "linked (local dev): $(VENDOR) -> $(LOCAL)"

## Drop the symlink, pull the published dev-main, clear cache — do this before committing.
bundle-release:
	rm -rf "$(VENDOR)"
	composer update uhifadhilabs/$(BUNDLE) --no-interaction
	bin/console cache:clear
	@echo "released (remote): $(BUNDLE) is now the composer version in composer.lock"

## The CI gate (deptrac + twig/container lint + tests).
check:
	composer check
