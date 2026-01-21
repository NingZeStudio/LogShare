export function parseLog(raw: string): string {
    const lines = raw.split('\n');
    let html = '<table>';

    lines.forEach((line, index) => {
        // Skip empty lines at end
        if (lines.length === index + 1 && line === '') return;

        const lineNumber = index + 1;
        let level = 'info';
        
        // Simple Level Detection
        if (line.match(/(\/|: |\[)WARN(ING)?(\]|:| )/i)) level = 'warning';
        else if (line.match(/(\/|: |\[)(ERR(OR)?|FATAL|SEVERE)(\]|:| )/i)) level = 'error';
        else if (line.match(/(\/|: |\[)(DEBUG)(\]|:| )/i)) level = 'debug';
        
        const entryClass = level === 'error' ? 'entry-error' : 'entry-no-error';
        const levelClass = `level-${level}`;

        // Format Content
        let formatted = formatContent(line);
        
        // Compact HTML to avoid whitespace issues
        html += `<tr class="entry ${entryClass}"><td class="line-number-container"><a href="#L${lineNumber}" id="L${lineNumber}" class="line-number">${lineNumber}</a></td><td><span class="level ${levelClass}">${formatted}</span></td></tr>`;
    });

    html += '</table>';
    return html;
}

function formatContent(text: string): string {
    // 1. HTML Escape
    let out = text.replace(/&/g, "&amp;")
                  .replace(/</g, "&lt;")
                  .replace(/>/g, "&gt;")
                  .replace(/"/g, "&quot;")
                  .replace(/'/g, "&#039;");

    // 2. Formatting Codes (§x)
    const styleMap: Record<string, string> = {
        '0': 'format-black', '1': 'format-darkblue', '2': 'format-darkgreen', '3': 'format-darkaqua',
        '4': 'format-darkred', '5': 'format-darkpurple', '6': 'format-gold', '7': 'format-gray',
        '8': 'format-darkgray', '9': 'format-blue', 'a': 'format-green', 'b': 'format-aqua',
        'c': 'format-red', 'd': 'format-lightpurple', 'e': 'format-yellow', 'f': 'format-white',
        'k': 'format-obfuscated', 'l': 'format-bold', 'm': 'format-strike', 'n': 'format-underline',
        'o': 'format-italic', 'r': 'format-reset'
    };

    out = out.replace(/§([0-9a-fk-or])/gi, (match, code) => {
        const cls = styleMap[code.toLowerCase()];
        if (cls) {
            return `<span class="${cls}">`;
        }
        return match;
    });

    return out;
}
