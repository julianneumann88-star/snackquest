(()=>{'use strict';
const one=(selector,context=document)=>context.querySelector(selector);
const currentUrl=()=>one('[data-share-url]')?.value||location.href;
document.querySelectorAll('[data-native-share]').forEach(button=>button.addEventListener('click',async()=>{
  const data={title:button.dataset.shareTitle||one('[data-share-title]')?.dataset.shareTitle||'SnackQuest',text:'Geteilt mit SnackQuest',url:currentUrl()};
  if(navigator.share){try{await navigator.share(data);return}catch(error){if(error?.name==='AbortError')return}}
  try{await navigator.clipboard.writeText(data.url);button.textContent='Link kopiert'}catch{location.assign(data.url)}
}));
document.querySelectorAll('[data-copy-share]').forEach(button=>button.addEventListener('click',async()=>{
  try{await navigator.clipboard.writeText(currentUrl());button.textContent='Link kopiert'}catch{one('[data-share-url]')?.select()}
}));
document.querySelectorAll('[data-download-card]').forEach(button=>button.addEventListener('click',()=>{
  const card=one('[data-share-card]');if(!card)return;const canvas=document.createElement('canvas');canvas.width=1200;canvas.height=630;const ctx=canvas.getContext('2d');if(!ctx)return;
  ctx.fillStyle='#fff9e9';ctx.fillRect(0,0,1200,630);ctx.fillStyle='#dfff47';ctx.fillRect(0,0,1200,24);ctx.fillStyle='#1f291f';ctx.font='700 30px system-ui';ctx.fillText('SNACKQUEST · GETEILT',70,90);
  const title=card.dataset.shareTitle||'SnackQuest';ctx.font='800 70px system-ui';const words=title.split(/\s+/);let line='',y=210;for(const word of words){const next=line?line+' '+word:word;if(ctx.measureText(next).width>820&&line){ctx.fillText(line,70,y);line=word;y+=85}else line=next}ctx.fillText(line,70,y);
  const rating=card.dataset.shareRating;if(rating){ctx.fillStyle='#ff6f32';ctx.beginPath();ctx.arc(1010,260,120,0,Math.PI*2);ctx.fill();ctx.fillStyle='#1f291f';ctx.textAlign='center';ctx.font='900 96px system-ui';ctx.fillText(rating,1010,285);ctx.font='700 30px system-ui';ctx.fillText('/10',1010,330);ctx.textAlign='start'}
  ctx.fillStyle='#1f291f';ctx.font='500 28px system-ui';ctx.fillText('Scannen. Bewerten. Wiederfinden.',70,560);ctx.font='500 24px system-ui';ctx.fillText('julian-neumann.org/snackquest',720,560);const link=document.createElement('a');link.download='snackquest-karte.png';link.href=canvas.toDataURL('image/png');link.click();
}));
})();
