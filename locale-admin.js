window.aksoAdminLocale = {
    gk: {
        createGkPage: 'Krei gazetaran komunikon',
        setup: {
            title: 'Krei gazetaran komunikon',
            gkNum: 'Numero',
            gkTitle: 'Titolo',
        },
        gkTitleFmt: (num, title) => `${title} (GK ${num})`,
        // the regex should match the title format
        gkTitleRe: /(?<title>.*?)\s*\(GK\s*(?<num>\d+)\)/,
        aliases: num => [`/gk/${num}`, `/gk/${num}a1`],
        sendToSubs: {
            send: 'Sendi al abonantoj',
            willSend: 'Sendos al ĉiuj abonantoj',
            didSend: 'Sendita al abonantoj',
            willUnset: 'Forigos etikedon “Jam sendita al abonantoj”',
        },
    },
};
