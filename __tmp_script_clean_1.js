
(function(){
  try {
    var c = localStorage.getItem('atlas_branding');
    if (!c) return;
    var b = JSON.parse(c);
    var m = document.querySelector('.loader-brand-mark');
    var n = document.querySelector('.loader-text h1');
    if (m) {
      if (b.logo) m.innerHTML = '<img src="'+b.logo+'" style="width:100%;height:100%;object-fit:cover;border-radius:16px" alt="Logo">';
      else m.textContent = (b.bankName||'A').charAt(0);
      if (b.primaryColor && !b.logo) m.style.background = 'linear-gradient(135deg,'+b.primaryColor+','+(b.accentColor||'#67e8b5')+')';
    }
    if (n) n.textContent = (b.bankNameShort||b.bankName||'Atlas Bank') + ' Enterprise';
    if (b.primaryColor) {
      document.querySelectorAll('.loader-ring').forEach(function(r,i){
        r.style.borderTopColor = i===0 ? b.primaryColor : (i===1 ? (b.accentColor||'#67e8b5') : '#ffbe69');
      });
    }
  } catch(e){}
})();
