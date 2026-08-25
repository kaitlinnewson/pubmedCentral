#!/bin/bash

set -e

# Run the plugin's unit tests first, so a failure is reported before the slower
# browser tests run. The PKP test bootstrap constructs the full application and
# connects to the database, both of which are already set up by this point.
# The tests are scoped by path rather than by --testsuite ApplicationPlugins,
# which would also run the tests of every other bundled plugin.
php lib/pkp/lib/vendor/bin/phpunit \
    --configuration lib/pkp/tests/phpunit.xml \
    plugins/generic/pubmedCentral/tests

# Install poppler-utils (pdftotext) and uncomment the pdftotext index command in
# config.inc.php so the exported JATS includes body text.
sudo apt-get update
sudo apt-get install -y --no-install-recommends poppler-utils
sed -i 's|^;\s*\(index\[application/pdf\]\s*=.*pdftotext.*\)|\1|' config.inc.php

npx cypress run --headless --browser chrome --config '{"specPattern":["plugins/generic/pubmedCentral/cypress/tests/functional/*.cy.{js,jsx,ts,tsx}"]}'
