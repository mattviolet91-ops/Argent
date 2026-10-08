// Chargé dans <head> : applique le thème et les options d'affichage avant l'affichage (évite le flash).
(function () {
  try {
    var root = document.documentElement;
    var theme = localStorage.getItem('theme');
    if (theme === 'light' || theme === 'dark') {
      root.setAttribute('data-theme', theme);
    }
    // Option de ce téléphone : grands boutons.
    if (localStorage.getItem('pref-big') === '1') { root.setAttribute('data-big', ''); }
    // Ancienne option « plein soleil » retirée : on efface le choix enregistré.
    localStorage.removeItem('pref-sun');
  } catch (e) { /* stockage indisponible : on suit le réglage du téléphone */ }
})();
