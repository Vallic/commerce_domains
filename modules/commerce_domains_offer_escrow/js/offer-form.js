/**
 * @file
 * Empties the offer amount while the buyer negotiates on Escrow.com.
 *
 * The field is hidden then, and the form refuses an amount with that choice.
 * Also runs on load, so a buyer the form refused can simply send it again.
 */
((Drupal, once) => {
  Drupal.behaviors.commerceDomainsOfferEscrow = {
    attach(context) {
      once(
        'commerce-domains-offer-escrow',
        'form[id^="commerce-domains-offer-form"] input[name="escrow"]',
        context,
      ).forEach((escrow) => {
        const { amount } = escrow.form.elements;
        const empty = () => {
          if (escrow.checked) {
            amount.value = '';
          }
        };
        escrow.addEventListener('change', empty);
        empty();
      });
    },
  };
})(Drupal, once);
