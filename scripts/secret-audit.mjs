import fs from 'node:fs';
import path from 'node:path';

const root=path.resolve(import.meta.dirname,'..');
const ignored=new Set(['.git','node_modules','test-results','playwright-report','logs','storage']);
const textExt=new Set(['.php','.js','.mjs','.json','.md','.yml','.yaml','.txt','.xml','.css','.htaccess','.example','.ps1']);
const findings=[];
const patterns=[
  ['private key',/-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/],
  ['OpenAI-style secret',/\bsk-(?:proj-)?[A-Za-z0-9_-]{20,}/],
  ['GitHub token',/\bgh[opusr]_[A-Za-z0-9]{20,}/],
  ['assigned secret',/(?:client_secret|api_key|db_pass)\s*['"]?\s*(?:=>|=|:)\s*['"][^'"\s]{12,}['"]/i],
];
function walk(dir){for(const entry of fs.readdirSync(dir,{withFileTypes:true})){if(ignored.has(entry.name)||entry.name==='config.local.php'||entry.name==='google-oauth.json')continue;const full=path.join(dir,entry.name);if(entry.isDirectory()){walk(full);continue}const ext=entry.name==='.htaccess'?'.htaccess':path.extname(entry.name).toLowerCase();if(!textExt.has(ext))continue;const content=fs.readFileSync(full,'utf8');for(const[label,pattern]of patterns){if(pattern.test(content))findings.push(`${path.relative(root,full)}: ${label}`)}}}
walk(root);
if(findings.length){console.error('Potential secrets found:\n'+findings.join('\n'));process.exit(1)}
console.log('Secret audit passed.');
