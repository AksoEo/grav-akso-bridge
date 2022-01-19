window.aksoAdminLocale = {
    gk: {
        createGkPage: '[[Add GK]]',
        setup: {
            title: '[[Add GK Article]]',
            gkNum: '[[GK Number]]',
            gkTitle: '[[Article Title]]',
        },
        gkTitleFmt: (num, title) => `${title} (GK ${num})`,
        // the regex should match the title format
        gkTitleRe: /(?<title>.*?)\s*\(GK\s*(?<num>\d+)\)/,
        aliases: num => [`/gk/${num}`, `/gk/${num}a1`],
        sendToSubs: {
            send: '[[Send to subscribers]]',
            willSend: '[[Will send to subscribers]]',
            didSend: '[[Sent to subscribers]]',
            willUnset: '[[Un-marking as “sent to subscribers”]]',
        },
    },
};
