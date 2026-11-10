---
name: Bug report
about: Something is broken or behaves incorrectly
labels: bug
body:
  - type: dropdown
    attributes:
      label: Component
      options:
        - Backend (admin panel / API)
        - WooCommerce plugin
        - Android app
        - Tests / docs
    validations:
      required: true
  - type: input
    attributes:
      label: Versions
      description: Peakopay component version + PHP/MySQL or WordPress/WooCommerce or Android version
      placeholder: "backend 2.2.0, PHP 8.2, MariaDB 10.6"
    validations:
      required: true
  - type: textarea
    attributes:
      label: What happened?
      description: Expected vs actual behaviour. NEVER include real card numbers, passwords or API keys.
    validations:
      required: true
  - type: textarea
    attributes:
      label: Steps to reproduce
    validations:
      required: true
  - type: textarea
    attributes:
      label: Logs / screenshots
      description: Relevant lines from backend/storage/logs/ (errors-*.log) if applicable.
