window.whmpveI18n = ({locale, language, messages}) => {
  let formatLocale;
  try { formatLocale = Intl.getCanonicalLocales(locale || undefined)[0]; } catch {}
  return {
    locale: formatLocale,
    language,
    t(key, values = {}) {
      return (messages[key] ?? key).replace(/\{([a-z_]+)\}/g,
        (placeholder, name) => Object.hasOwn(values, name) ? String(values[name]) : placeholder);
    }
  };
};
