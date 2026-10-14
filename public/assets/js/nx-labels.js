/* Nexora Logistics — shared label + format helpers.
   Used by every admin page and every public page.
   Single source of truth for emoji, colour, and formatting. */
(function (global) {
  'use strict';

  /* ---------- Transport ---------- */
  var TRANSPORTS = {
    air:      { emoji: '✈️', label: 'Air Freight',   color: '#f0b429' },
    flight:   { emoji: '✈️', label: 'Air Freight',   color: '#f0b429' },
    air_way:  { emoji: '✈️', label: 'Air Freight',   color: '#f0b429' },
    sea:      { emoji: '🚢', label: 'Ocean Freight', color: '#2563eb' },
    ship:     { emoji: '🚢', label: 'Ocean Freight', color: '#2563eb' },
    ocean:    { emoji: '🚢', label: 'Ocean Freight', color: '#2563eb' },
    rail:     { emoji: '🚆', label: 'Rail',          color: '#16a34a' },
    train:    { emoji: '🚆', label: 'Rail',          color: '#16a34a' },
    road:     { emoji: '🚚', label: 'Road',          color: '#64748b' },
    truck:    { emoji: '🚚', label: 'Road',          color: '#64748b' },
    vehicle:  { emoji: '🚚', label: 'Road',          color: '#64748b' },
    bike:     { emoji: '🏍️', label: 'Courier',       color: '#ec4899' },
    last_mile:{ emoji: '📦', label: 'Last Mile',     color: '#7c3aed' }
  };

  /**
   * nxTransport(type, vehicleName)
   * Returns { emoji, label, color }
   * Priority: vehicleName keyword > type keyword > default truck
   */
  function nxTransport(type, vehicleName) {
    var v = ((vehicleName || '') + ' ' + (type || '')).toLowerCase();
    if (/air|plane|flight|jet|aircraft/.test(v)) return TRANSPORTS.air;
    if (/sea|ship|boat|vessel|container|cargo/.test(v)) return TRANSPORTS.sea;
    if (/rail|train/.test(v)) return TRANSPORTS.rail;
    if (/bike|motorcycle|scooter|courier/.test(v)) return TRANSPORTS.bike;
    if (/van|last.?mile|delivery/.test(v)) return TRANSPORTS.last_mile;
    if (/truck|lorry|road|vehicle/.test(v)) return TRANSPORTS.road;
    var t = (type || '').toLowerCase();
    return TRANSPORTS[t] || TRANSPORTS.road;
  }

  /* ---------- Status ---------- */
  var STATUSES = {
    pending:         { label: 'Pending',       color: '#f59e0b', bg: 'rgba(245,158,11,.12)' },
    in_transit:      { label: 'In transit',    color: '#16a34a', bg: 'rgba(22,163,74,.12)' },
    out_for_delivery:{ label: 'Out for delivery', color: '#2563eb', bg: 'rgba(37,99,235,.12)' },
    delayed:         { label: 'Delayed',       color: '#f97316', bg: 'rgba(249,115,22,.12)' },
    on_hold:         { label: 'On hold',       color: '#dc2626', bg: 'rgba(220,38,38,.12)' },
    held:            { label: 'On hold',       color: '#dc2626', bg: 'rgba(220,38,38,.12)' },
    seized:          { label: 'Seized',        color: '#991b1b', bg: 'rgba(153,27,27,.14)' },
    delivered:       { label: 'Delivered',     color: '#059669', bg: 'rgba(5,150,105,.12)' },
    cancelled:       { label: 'Cancelled',     color: '#64748b', bg: 'rgba(100,116,139,.12)' },
    returned:        { label: 'Returned',      color: '#7c3aed', bg: 'rgba(124,58,237,.12)' }
  };
  function nxStatus(s) {
    var k = String(s || '').toLowerCase().replace(/\s+/g, '_');
    return STATUSES[k] || { label: (s || '—'), color: '#64748b', bg: 'rgba(100,116,139,.12)' };
  }

  /* ---------- Country flag from a place name ---------- */
  var FLAGS = [
    [/united states|\busa?\b|u\.s\.a|america|los angeles|new york|chicago|miami|san francisco|seattle|houston|boston|las vegas/i, '🇺🇸'],
    [/united kingdom|\buk\b|britain|england|scotland|wales|london|manchester/i, '🇬🇧'],
    [/nigeria|lagos|abuja|port harcourt|kano/i, '🇳🇬'],
    [/china|shanghai|beijing|shenzhen|guangzhou|hong kong/i, '🇨🇳'],
    [/japan|tokyo|osaka|nagoya|yokohama/i, '🇯🇵'],
    [/germany|berlin|frankfurt|munich|hamburg/i, '🇩🇪'],
    [/france|paris|marseille|lyon/i, '🇫🇷'],
    [/netherlands|amsterdam|rotterdam/i, '🇳🇱'],
    [/singapore/i, '🇸🇬'],
    [/united arab emirates|\buae\b|dubai|abu dhabi/i, '🇦🇪'],
    [/india|mumbai|delhi|bangalore|chennai/i, '🇮🇳'],
    [/brazil|são paulo|sao paulo|rio de janeiro/i, '🇧🇷'],
    [/south africa|johannesburg|cape town|durban/i, '🇿🇦'],
    [/canada|toronto|vancouver|montreal|ottawa/i, '🇨🇦'],
    [/australia|sydney|melbourne|perth|brisbane/i, '🇦🇺'],
    [/spain|madrid|barcelona/i, '🇪🇸'],
    [/italy|rome|milan|naples/i, '🇮🇹'],
    [/turkey|istanbul|ankara/i, '🇹🇷'],
    [/egypt|cairo|alexandria/i, '🇪🇬'],
    [/kenya|nairobi|mombasa/i, '🇰🇪'],
    [/ghana|accra|kumasi/i, '🇬🇭'],
    [/mexico|mexico city|guadalajara/i, '🇲🇽'],
    [/russia|moscow|saint petersburg/i, '🇷🇺'],
    [/south korea|\bkorea\b|seoul|busan/i, '🇰🇷'],
    [/saudi|riyadh|jeddah/i, '🇸🇦'],
    [/pakistan|karachi|lahore|islamabad/i, '🇵🇰'],
    [/indonesia|jakarta|surabaya/i, '🇮🇩'],
    [/thailand|bangkok|phuket/i, '🇹🇭'],
    [/vietnam|hanoi|ho chi minh/i, '🇻🇳'],
    [/philippines|manila|cebu/i, '🇵🇭'],
    [/malaysia|kuala lumpur/i, '🇲🇾'],
    [/argentina|buenos aires/i, '🇦🇷'],
    [/chile|santiago/i, '🇨🇱'],
    [/colombia|bogot|medell/i, '🇨🇴'],
    [/peru|lima/i, '🇵🇪'],
    [/ireland|dublin/i, '🇮🇪'],
    [/portugal|lisbon|porto/i, '🇵🇹'],
    [/belgium|brussels|antwerp/i, '🇧🇪'],
    [/sweden|stockholm|gothenburg/i, '🇸🇪'],
    [/norway|oslo/i, '🇳🇴'],
    [/denmark|copenhagen/i, '🇩🇰'],
    [/finland|helsinki/i, '🇫🇮'],
    [/poland|warsaw/i, '🇵🇱'],
    [/greece|athens/i, '🇬🇷'],
    [/israel|tel aviv|jerusalem/i, '🇮🇱'],
    [/morocco|casablanca|rabat/i, '🇲🇦'],
    [/ethiopia|addis ababa/i, '🇪🇹'],
    [/tanzania|dar es salaam/i, '🇹🇿']
  ];
  function nxFlag(place) {
    if (!place) return '📍';
    var t = String(place);
    for (var i = 0; i < FLAGS.length; i++) {
      if (FLAGS[i][0].test(t)) return FLAGS[i][1];
    }
    return '📍';
  }

  /* ---------- Date ---------- */
  /* Accepts: "2026-10-16T22:42:11", "2026-10-16 22:42:11", Unix-ish, Date object, empty */
  function nxDate(v, opts) {
    if (v === null || v === undefined || v === '') return '—';
    var s = String(v).replace('T', ' ');
    // strip milliseconds
    s = s.replace(/\.\d+/, '');
    // keep YYYY-MM-DD HH:MM
    s = s.replace(/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2})(:\d{2})?$/, '$1');
    // remove trailing seconds if the format is YYYY-MM-DD HH:MM:SS
    s = s.replace(/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}):\d{2}$/, '$1');
    return s;
  }

  /* ---------- Coordinates ---------- */
  function nxCoord(lat, lng, decimals) {
    var d = (typeof decimals === 'number') ? decimals : 4;
    var la = parseFloat(lat), ln = parseFloat(lng);
    if (isNaN(la) || isNaN(ln)) return '—';
    return la.toFixed(d) + ', ' + ln.toFixed(d);
  }

  /* ---------- Carrier ---------- */
  function nxCarrier(row) {
    if (!row) return null;
    return {
      name: row.name || '',
      code: row.code || '',
      logo: row.logo_public || row.logo_path ? ('/assets/carriers/' + row.logo_path) : (row.logo_url || null)
    };
  }

  /* ---------- Export ---------- */
  global.NX = {
    transport: nxTransport,
    status: nxStatus,
    flag: nxFlag,
    date: nxDate,
    coord: nxCoord,
    carrier: nxCarrier,
    transports: TRANSPORTS,
    statuses: STATUSES
  };
})(typeof window !== 'undefined' ? window : this);
