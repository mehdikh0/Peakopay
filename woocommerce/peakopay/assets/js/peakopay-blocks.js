/**
 * Peakopay — WooCommerce Checkout Blocks client registration.
 *
 * Dependency-free (no build step): registers the "peakopay" payment method
 * with the official WooCommerce Blocks registry (wc.wcBlocksRegistry) using
 * the settings provided server-side by Peakopay_Blocks_Support
 * (available as wc.wcSettings.getSetting('peakopay_data')).
 *
 * No payment data is processed here — placing the order invokes the server
 * side gateway (Peakopay_Gateway::process_payment) just like classic checkout.
 */
(function () {
  'use strict';

  var registry = window.wc && window.wc.wcBlocksRegistry;
  var wpElement = window.wp && window.wp.element;
  if (!registry || !wpElement || typeof registry.registerPaymentMethod !== 'function') {
    return; // not in a blocks checkout context
  }

  var settings = {};
  try {
    settings = (window.wc.wcSettings && window.wc.wcSettings.getSetting)
      ? window.wc.wcSettings.getSetting('peakopay_data', {})
      : {};
  } catch (err) {
    settings = {};
  }

  var title = (settings && settings.title) || 'Peakopay';
  var description = (settings && settings.description) || '';

  var el = wpElement.createElement;
  var Content = function () {
    return el('div', { style: { whiteSpace: 'pre-line' } }, description);
  };

  try {
    registry.registerPaymentMethod({
      name: 'peakopay',
      label: title,
      ariaLabel: title,
      content: el(Content, null),
      edit: el(Content, null),
      canMakePayment: function () { return true; },
      paymentMethodId: 'peakopay',
      supports: {
        features: (settings && settings.supports) || ['products']
      }
    });
  } catch (err) {
    // never break checkout because of a registration problem
    if (window.console && window.console.error) {
      window.console.error('Peakopay blocks registration failed', err);
    }
  }
})();
