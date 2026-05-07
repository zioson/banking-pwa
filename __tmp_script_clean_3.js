
document.addEventListener('DOMContentLoaded', function() {
  try { if (typeof lucide !== 'undefined') lucide.createIcons(); } catch(e) {}
  try { if (typeof setupActivityTracking === 'function') setupActivityTracking(); } catch(e) {}
  try { if (typeof initDigitFriendlyFigureInputs === 'function') initDigitFriendlyFigureInputs(); } catch(e) {}
});
