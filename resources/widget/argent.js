// Argent : widget pour l'écran d'accueil et l'écran verrouillé de l'iPhone (app Scriptable).
// Installé depuis l'app Argent (Réglages → Widgets iPhone). Ne partagez pas ce script :
// il contient la clé de ce widget, qui permet de lire le résumé (rien d'autre).
// Clé perdue ou téléphone perdu : retirez le widget dans Réglages, la clé ne marche plus.
//
// Paramètre du widget (facultatif) : « perso » ou « pro » pour n'afficher que cette partie.

const API = '%%API%%';
const KEY = '%%KEY%%';
const SCRIPT_VERSION = %%VERSION%%;

const C = {
  bg: Color.dynamic(new Color('#FFFFFF'), new Color('#1A2229')),
  text: Color.dynamic(new Color('#2C3E50'), new Color('#E6ECEF')),
  muted: Color.dynamic(new Color('#5F6F7D'), new Color('#9AA9B5')),
  accent: Color.dynamic(new Color('#1F7FA6'), new Color('#7FD3F0')),
  pos: Color.dynamic(new Color('#1E7F4F'), new Color('#5FD39A')),
  neg: Color.dynamic(new Color('#B42318'), new Color('#FF8A80')),
  warn: Color.dynamic(new Color('#8A5A00'), new Color('#F5C66B')),
  track: Color.dynamic(new Color('#E3E8EB'), new Color('#2E3A44')),
};
// Dessins (courbe, rond) : pas de couleur claire / sombre possible, une teinte qui va aux deux.
const DRAW = { line: '#2E9FCB', fill: '#3CBDE8' };

// Largeur des widgets selon l'iPhone (en points).
const SCREEN = (() => {
  try {
    const s = Device.screenSize();
    return Math.min(s.width, s.height) || 390;
  } catch (e) {
    return 390;
  }
})();
const PAD = 14;
const WIDE = SCREEN >= 428 ? 364 : SCREEN >= 414 ? 360 : SCREEN >= 390 ? 338 : SCREEN >= 375 ? 321 : 292;
const SMALL = SCREEN >= 428 ? 170 : SCREEN >= 414 ? 169 : SCREEN >= 390 ? 158 : SCREEN >= 375 ? 148 : 141;
const INNER = WIDE - PAD * 2 - 4;
const INNER_SMALL = SMALL - PAD * 2 - 4;

// ---- Données : résumé de l'app Argent, gardé sur le téléphone pour les moments sans réseau ----

async function load() {
  const view = String(args.widgetParameter || '').trim().toLowerCase().replace(/[^a-z]/g, '');
  const fm = FileManager.local();
  const cache = fm.joinPath(fm.documentsDirectory(), 'argent-widget-' + (view || 'defaut') + '.json');
  const req = new Request(API + (view ? '?vue=' + encodeURIComponent(view) : ''));
  req.headers = { Authorization: 'Bearer ' + KEY, Accept: 'application/json' };
  req.timeoutInterval = 20;
  try {
    const data = await req.loadJSON();
    const status = req.response ? req.response.statusCode : 0;
    if (status === 401) {
      if (fm.fileExists(cache)) {
        fm.remove(cache);
      }
      return { error: (data && data.error) || 'Ce widget a été retiré dans l\'app Argent.' };
    }
    if (status !== 200 || !data || !data.balance) {
      throw new Error('Réponse ' + status);
    }
    fm.writeString(cache, JSON.stringify(data));
    return { data: data, stale: false };
  } catch (e) {
    if (fm.fileExists(cache)) {
      try {
        return { data: JSON.parse(fm.readString(cache)), stale: true };
      } catch (ignored) {
        // Copie illisible : comme s'il n'y en avait pas.
      }
    }
    return { error: 'Pas de réseau pour l\'instant. Nouvel essai dans quelques minutes.' };
  }
}

// ---- Petits outils de mise en page ----

function text(parent, value, font, color, options) {
  const o = options || {};
  const t = parent.addText(String(value));
  t.font = font;
  t.textColor = color || C.text;
  t.lineLimit = o.lines || 1;
  if (o.scale) {
    t.minimumScaleFactor = o.scale;
  }
  if (o.right) {
    t.rightAlignText();
  }
  return t;
}

function row(parent) {
  const s = parent.addStack();
  s.layoutHorizontally();
  s.centerAlignContent();
  return s;
}

function column(parent, width) {
  const s = parent.addStack();
  s.layoutVertically();
  if (width) {
    s.size = new Size(width, 0);
  }
  return s;
}

function signColor(positive) {
  return positive ? C.pos : C.neg;
}

function trendColor(dir) {
  return dir === 'up' ? C.pos : dir === 'down' ? C.neg : C.muted;
}

function trendArrow(dir) {
  return dir === 'up' ? '↗︎ ' : dir === 'down' ? '↘︎ ' : '→ ';
}

// Barre de progression (budget, objectif) faite de deux blocs : suit le thème clair / sombre.
function bar(parent, percent, width, color) {
  const track = parent.addStack();
  track.layoutHorizontally();
  track.size = new Size(width, 6);
  track.cornerRadius = 3;
  track.backgroundColor = C.track;
  const filled = Math.round(Math.max(0, Math.min(1, percent / 100)) * width);
  if (filled >= 3) {
    const fill = track.addStack();
    fill.size = new Size(filled, 6);
    fill.cornerRadius = 3;
    fill.backgroundColor = color;
  }
  track.addSpacer();
}

// Courbe du solde sur 30 jours (valeurs entre 0 et 1 : la forme, sans montant).
function sparkline(values, width, height) {
  const ctx = new DrawContext();
  ctx.size = new Size(width, height);
  ctx.opaque = false;
  ctx.respectScreenScale = true;
  if (!values || values.length < 2) {
    return ctx.getImage();
  }
  const top = 3;
  const points = values.map((v, i) => new Point(i * (width / (values.length - 1)), top + (1 - v) * (height - top * 2)));

  const area = new Path();
  area.move(new Point(0, height));
  points.forEach((p) => area.addLine(p));
  area.addLine(new Point(width, height));
  area.closeSubpath();
  ctx.addPath(area);
  ctx.setFillColor(new Color(DRAW.fill, 0.18));
  ctx.fillPath();

  const line = new Path();
  line.move(points[0]);
  points.slice(1).forEach((p) => line.addLine(p));
  ctx.addPath(line);
  ctx.setStrokeColor(new Color(DRAW.line));
  ctx.setLineWidth(2);
  ctx.strokePath();
  return ctx.getImage();
}

function addSparkline(parent, values, width, height) {
  const image = parent.addImage(sparkline(values, width, height));
  image.imageSize = new Size(width, height);
}

// Rond de l'écran verrouillé : pourcentage du budget (ou rien), texte au milieu.
function ring(percent, label, size) {
  const ctx = new DrawContext();
  ctx.size = new Size(size, size);
  ctx.opaque = false;
  ctx.respectScreenScale = true;
  const center = size / 2;
  const radius = size / 2 - 4;
  const arc = (from, to) => {
    const path = new Path();
    const steps = Math.max(2, Math.round((to - from) * 60));
    for (let i = 0; i <= steps; i++) {
      const a = -Math.PI / 2 + 2 * Math.PI * (from + (to - from) * (i / steps));
      const p = new Point(center + radius * Math.cos(a), center + radius * Math.sin(a));
      if (i === 0) {
        path.move(p);
      } else {
        path.addLine(p);
      }
    }
    return path;
  };
  ctx.setLineWidth(5);
  ctx.addPath(arc(0, 1));
  ctx.setStrokeColor(new Color('#FFFFFF', 0.25));
  ctx.strokePath();
  if (percent !== null && percent > 0) {
    ctx.addPath(arc(0, Math.min(1, percent / 100)));
    ctx.setStrokeColor(Color.white());
    ctx.strokePath();
  }
  ctx.setTextColor(Color.white());
  ctx.setTextAlignedCenter();
  ctx.setFont(Font.boldRoundedSystemFont(percent !== null ? 16 : 12));
  ctx.drawTextInRect(percent !== null ? percent + '%' : label, new Rect(4, center - 13, size - 8, 20));
  if (percent !== null) {
    ctx.setFont(Font.mediumSystemFont(8));
    ctx.drawTextInRect(label, new Rect(4, center + 7, size - 8, 12));
  }
  return ctx.getImage();
}

// ---- Widgets ----

function header(w, d, stale, withTime) {
  const h = row(w);
  text(h, 'ARGENT', Font.boldSystemFont(11), C.accent);
  if (d.view && d.view !== 'all') {
    text(h, ' · ' + String(d.view_label).toUpperCase(), Font.semiboldSystemFont(11), C.muted);
  }
  h.addSpacer();
  if (stale) {
    text(h, '⚠︎ ' + (withTime ? d.time : ''), Font.mediumSystemFont(10), C.warn);
  } else if (withTime) {
    text(h, '↻ ' + d.time, Font.systemFont(10), C.muted);
  }
}

function small(w, d, stale) {
  header(w, d, stale, false);
  w.addSpacer(6);
  text(w, d.balance.text, Font.boldRoundedSystemFont(26), d.balance.negative ? C.neg : C.text, { scale: 0.45 });
  text(w, trendArrow(d.trend.dir) + d.trend.text, Font.mediumSystemFont(10), trendColor(d.trend.dir), { scale: 0.7 });
  w.addSpacer(6);
  addSparkline(w, d.history, INNER_SMALL, 28);
  w.addSpacer();
  const m = row(w);
  text(m, d.month.label, Font.systemFont(12), C.muted, { scale: 0.7 });
  m.addSpacer();
  text(m, d.month.result, Font.semiboldSystemFont(12), signColor(d.month.positive), { scale: 0.6, right: true });
}

function line(parent, label, value, color, strong) {
  const r = row(parent);
  text(r, label, Font.systemFont(12), C.muted);
  r.addSpacer();
  text(r, value, strong ? Font.semiboldSystemFont(12) : Font.mediumSystemFont(12), color, { scale: 0.6, right: true });
}

function medium(w, d, stale) {
  header(w, d, stale, true);
  w.addSpacer(6);
  const body = row(w);
  body.topAlignContent();
  const half = Math.floor((INNER - 16) / 2);

  const left = column(body, half);
  text(left, d.balance.text, Font.boldRoundedSystemFont(24), d.balance.negative ? C.neg : C.text, { scale: 0.45 });
  text(left, trendArrow(d.trend.dir) + d.trend.text, Font.mediumSystemFont(10), trendColor(d.trend.dir), { scale: 0.7 });
  left.addSpacer(6);
  addSparkline(left, d.history, half, 34);

  body.addSpacer(16);
  const right = column(body, half);
  text(right, d.month.label, Font.semiboldSystemFont(12), C.text);
  right.addSpacer(2);
  line(right, 'Entrées', d.month.income, C.pos);
  line(right, 'Sorties', d.month.expense, C.text);
  line(right, 'Résultat', d.month.result, signColor(d.month.positive), true);
  right.addSpacer(5);
  if (d.budget) {
    const b = row(right);
    text(b, 'Budgets', Font.systemFont(11), C.muted);
    b.addSpacer();
    text(b, d.budget.percent + ' %', Font.semiboldSystemFont(11), d.budget.over ? C.neg : C.text);
    right.addSpacer(3);
    bar(right, d.budget.percent, half, d.budget.over ? C.neg : d.budget.percent >= 85 ? C.warn : C.pos);
  } else {
    line(right, 'Fin de mois', d.forecast.text, d.forecast.negative ? C.neg : C.text);
  }
  w.addSpacer();
}

function large(w, d, stale) {
  header(w, d, stale, true);
  w.addSpacer(8);
  text(w, d.balance.text, Font.boldRoundedSystemFont(32), d.balance.negative ? C.neg : C.text, { scale: 0.5 });
  text(w, trendArrow(d.trend.dir) + d.trend.text, Font.mediumSystemFont(12), trendColor(d.trend.dir));
  w.addSpacer(6);
  addSparkline(w, d.history, INNER, 46);
  w.addSpacer(10);

  const title = row(w);
  text(title, d.month.label, Font.semiboldSystemFont(13), C.text);
  title.addSpacer();
  text(title, 'Fin de mois ≈ ' + d.forecast.text, Font.systemFont(11), d.forecast.negative ? C.neg : C.muted, { scale: 0.7 });
  w.addSpacer(4);
  const m = row(w);
  [['Entrées', d.month.income, C.pos], ['Sorties', d.month.expense, C.text], ['Résultat', d.month.result, signColor(d.month.positive)]].forEach((cell, i) => {
    if (i > 0) {
      m.addSpacer();
    }
    const c = column(m);
    text(c, cell[0], Font.systemFont(11), C.muted);
    text(c, cell[1], Font.semiboldRoundedSystemFont(15), cell[2], { scale: 0.6 });
  });

  if (d.budget) {
    w.addSpacer(10);
    const b = row(w);
    text(b, 'Budgets du mois', Font.systemFont(12), C.muted);
    b.addSpacer();
    text(b, d.budget.percent + ' %', Font.semiboldSystemFont(12), d.budget.over ? C.neg : C.text);
    w.addSpacer(4);
    bar(w, d.budget.percent, INNER, d.budget.over ? C.neg : d.budget.percent >= 85 ? C.warn : C.pos);
    w.addSpacer(3);
    text(w, d.budget.left + (d.budget.over_count > 0 ? ' · ' + d.budget.over_count + ' dépassé' + (d.budget.over_count > 1 ? 's' : '') : ''), Font.systemFont(11), C.muted);
  }

  const upcoming = (d.upcoming || []).slice(0, d.budget ? 3 : 4);
  if (upcoming.length) {
    w.addSpacer(10);
    text(w, 'À venir', Font.semiboldSystemFont(12), C.text);
    w.addSpacer(2);
    upcoming.forEach((u) => {
      const r = row(w);
      const day = column(r, 58);
      text(day, u.day, Font.systemFont(12), C.muted);
      text(r, u.label, Font.systemFont(12), C.text);
      r.addSpacer();
      text(r, u.text, Font.mediumSystemFont(12), u.income ? C.pos : C.text, { right: true });
    });
  }

  w.addSpacer();
  if (d.script > SCRIPT_VERSION) {
    text(w, 'Nouvelle version du widget : app Argent → Réglages → Widgets iPhone', Font.systemFont(10), C.warn, { lines: 2 });
  } else if (d.to_classify > 0) {
    text(w, d.to_classify + ' opération' + (d.to_classify > 1 ? 's' : '') + ' à classer perso / pro', Font.systemFont(11), C.warn);
  } else if (d.goal) {
    const g = row(w);
    text(g, d.goal.name, Font.systemFont(11), C.muted);
    g.addSpacer(6);
    text(g, d.goal.percent + ' %', Font.semiboldSystemFont(11), C.text);
  }
}

function accessoryRectangular(w, d) {
  text(w, 'Argent' + (d.view && d.view !== 'all' ? ' · ' + d.view_label : ''), Font.boldSystemFont(13), Color.white());
  text(w, d.balance.text, Font.boldRoundedSystemFont(20), Color.white(), { scale: 0.6 });
  text(w, d.month.label + ' ' + d.month.result, Font.systemFont(12), Color.white(), { scale: 0.7 });
}

function accessoryCircular(w, d) {
  w.addAccessoryWidgetBackground = true;
  const image = d.budget ? ring(d.budget.percent, 'budget', 64) : ring(null, d.balance.short, 64);
  const i = w.addImage(image);
  i.imageSize = new Size(64, 64);
  i.centerAlignImage();
}

function accessoryInline(w, d) {
  text(w, 'Argent ' + d.balance.text, Font.body(), Color.white());
}

function failure(w, family, message) {
  if (family.indexOf('accessory') === 0) {
    text(w, 'Argent', Font.boldSystemFont(12), Color.white());
    if (family !== 'accessoryInline') {
      text(w, 'Indisponible', Font.systemFont(11), Color.white());
    }
    return;
  }
  text(w, 'ARGENT', Font.boldSystemFont(11), C.accent);
  w.addSpacer(6);
  text(w, message, Font.systemFont(13), C.muted, { lines: 5, scale: 0.8 });
  w.addSpacer();
}

function build(family, result) {
  const w = new ListWidget();
  if (family.indexOf('accessory') !== 0) {
    w.backgroundColor = C.bg;
    w.setPadding(PAD, PAD, PAD, PAD);
  }
  // Toutes les 15 minutes au plus (l'iPhone décide du moment exact).
  w.refreshAfterDate = new Date(Date.now() + 15 * 60 * 1000);
  if (!result.data) {
    failure(w, family, result.error);
    return w;
  }
  const d = result.data;
  if (d.url) {
    w.url = d.url;
  }
  ({
    small: small,
    medium: medium,
    large: large,
    extraLarge: large,
    accessoryRectangular: accessoryRectangular,
    accessoryCircular: accessoryCircular,
    accessoryInline: accessoryInline,
  }[family] || medium)(w, d, result.stale);
  return w;
}

// ---- Lancement : dans le widget, ou aperçu quand on ouvre le script dans Scriptable ----

const result = await load();
if (config.runsInWidget) {
  Script.setWidget(build(config.widgetFamily || 'medium', result));
} else {
  const choices = [
    ['Petit', 'small', 'presentSmall'],
    ['Moyen', 'medium', 'presentMedium'],
    ['Grand', 'large', 'presentLarge'],
    ['Écran verrouillé (rectangle)', 'accessoryRectangular', 'presentAccessoryRectangular'],
    ['Écran verrouillé (rond)', 'accessoryCircular', 'presentAccessoryCircular'],
  ];
  const menu = new Alert();
  menu.title = 'Widget Argent';
  menu.message = result.data
    ? 'Ça marche' + (result.stale ? ' (sans réseau : dernier résumé reçu à ' + result.data.time + ')' : '') + '. Choisissez un aperçu, puis ajoutez le widget depuis l\'écran d\'accueil.'
    : result.error;
  choices.forEach((c) => menu.addAction(c[0]));
  menu.addCancelAction('Fermer');
  const picked = choices[await menu.presentSheet()];
  if (picked) {
    await build(picked[1], result)[picked[2]]();
  }
}
Script.complete();
