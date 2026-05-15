#!/bin/bash

set -e

# Install poppler-utils (pdftotext) and uncomment the pdftotext index command in
# config.inc.php so the exported JATS includes body text.
sudo apt-get update
sudo apt-get install -y --no-install-recommends poppler-utils
sed -i 's|^;\s*\(index\[application/pdf\]\s*=.*pdftotext.*\)|\1|' config.inc.php

npx cypress run --headless --browser chrome --config '{"specPattern":["plugins/generic/pubmedCentral/cypress/tests/functional/*.cy.{js,jsx,ts,tsx}"]}'
