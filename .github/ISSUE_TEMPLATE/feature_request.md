---
name: Feature request
about: Suggest an improvement
labels: enhancement
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
  - type: textarea
    attributes:
      label: Problem to solve / proposed solution
      description: Keep the project constraints in mind (no Composer, no external services, offline-first).
    validations:
      required: true
