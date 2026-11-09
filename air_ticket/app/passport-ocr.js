/* Biz-Flights passport autofill — browser-only OCR of the passport MRZ.
 * Images never leave this browser. OCR assets download on demand from jsDelivr.
 * ICAO TD3: two 44-character machine readable lines, with check digits.
 * This module does NOT authenticate a passport; all fields must be reviewed.
 */
(function (root) {
  'use strict';
  const NATIONALITIES = {
    BGD:'Bangladeshi',IND:'Indian',MYS:'Malaysian',GBR:'British',USA:'American',CAN:'Canadian',AUS:'Australian',
    ARE:'Emirati',SAU:'Saudi Arabian',QAT:'Qatari',OMN:'Omani',KWT:'Kuwaiti',BHR:'Bahraini',
    THA:'Thai',SGP:'Singaporean',IDN:'Indonesian',PAK:'Pakistani',NPL:'Nepali',LKA:'Sri Lankan',
    CHN:'Chinese',JPN:'Japanese',KOR:'South Korean',PHL:'Filipino',VNM:'Vietnamese',
    TUR:'Turkish',DEU:'German',FRA:'French',ITA:'Italian',ESP:'Spanish',NLD:'Dutch',PRT:'Portuguese',
    CHE:'Swiss',SWE:'Swedish',NOR:'Norwegian',DNK:'Danish',FIN:'Finnish',RUS:'Russian',
    POL:'Polish',IRL:'Irish',AUT:'Austrian',BEL:'Belgian',EGY:'Egyptian',ZAF:'South African',
    NGA:'Nigerian',KEN:'Kenyan',BRA:'Brazilian',ARG:'Argentine',MEX:'Mexican',NZL:'New Zealander'
  };
  const clean = s => String(s||'').toUpperCase().replace(/[«‹〈《]/g,'<').replace(/[^A-Z0-9<]/g,'');
  const digit = s => String(s||'').replace(/[OQD]/g,'0').replace(/[IL]/g,'1').replace(/S/g,'5').replace(/B/g,'8').replace(/Z/g,'2').replace(/G/g,'6');
  const digitValue = char => char==='<'?0:/^[0-9]$/.test(char)?Number(char):/^[A-Z]$/.test(char)?char.charCodeAt(0)-55:0;
  function checkDigit(s) { const weights=[7,3,1];let n=0;for(let i=0;i<s.length;i++)n+=digitValue(s[i])*weights[i%3];return String(n%10); }
  function validCheck(text, check) {return /^[0-9]$/.test(digit(check)) && checkDigit(text)===digit(check);}
  function dateFromMRZ(value, kind, referenceDate) {
    const s=digit(value);
    if(!/^\d{6}$/.test(s))return '';
    const yy=Number(s.slice(0,2)),mm=Number(s.slice(2,4)),dd=Number(s.slice(4,6));
    const now=referenceDate||new Date();
    let year=2000+yy;
    if(kind==='birth'){
      if(year>now.getFullYear() || (year===now.getFullYear() && mm-1>now.getMonth()))year-=100;
    }else if(year>now.getFullYear()+20){year-=100;}
    const d=new Date(Date.UTC(year,mm-1,dd));
    if(d.getUTCFullYear()!==year||d.getUTCMonth()!==mm-1||d.getUTCDate()!==dd)return '';
    return `${year}-${String(mm).padStart(2,'0')}-${String(dd).padStart(2,'0')}`;
  }
  // OCR often reads the MRZ filler '<' as C, K, L, I, X, E or 1, e.g.
  // "NURJAHAN<CLLLLLLLLLKLLLL". A long trailing run of such characters that
  // repeats one letter 5+ times cannot be a real name, so it becomes filler.
  // A short real given name like "LILI" is never touched.
  function normalizeNameFiller(line1) {
    const head=line1.slice(0,5),name=line1.slice(5);
    const fixed=name.replace(/<[<CKLIXE1]{7,}$/,run=>/([CKLIXE1])\1{4,}/.test(run.replace(/</g,''))?'<'.repeat(run.length):run);
    return head+fixed;
  }
  // Where MRZ line 1 starts: "P<" + country. OCR often reads that '<' as K, C, L, X or E.
  function line1Start(raw) {
    const exact=raw.indexOf('P<');
    if(exact>=0)return {at:exact,text:raw};
    const m=/P[KCLXE]([A-Z]{3})[A-Z]/.exec(raw);
    if(!m)return null;
    return {at:m.index,text:raw.slice(0,m.index+1)+'<'+raw.slice(m.index+2)};
  }
  // Line 2 candidates: every 44-character window (OCR may add junk before or
  // after), plus a padded copy when one or two trailing characters were lost.
  function line2Windows(raw2) {
    const out=[];
    for(let o=0;o+44<=raw2.length;o++)out.push(raw2.slice(o,o+44));
    if(raw2.length>=42&&raw2.length<44)out.push(raw2.padEnd(44,'<'));
    return out;
  }
  // The passport number mixes letters and digits, so OCR swaps such as 0/O/D,
  // 1/I, 8/B or 5/S break its check digit. Try ONE swap only, and keep it only
  // when both the number's check digit and the composite check digit pass and
  // exactly one swap does so. (More swaps make chance matches far too likely.)
  const LOOKALIKE={'0':'OD','O':'0D','D':'0O','Q':'0O','1':'IL','I':'1','L':'1','2':'Z','Z':'2','5':'S','S':'5','6':'G','G':'6','8':'B','B':'8'};
  function repairPassportNumber(line2) {
    const field=line2.slice(0,9),check=line2[9];
    if(validCheck(field,check))return line2;
    const matches=new Set(),toDigit=new Set();
    for(let i=0;i<9;i++)for(const a of LOOKALIKE[field[i]]||''){
      const fixed=field.slice(0,i)+a+field.slice(i+1)+line2.slice(9);
      if(!validCheck(fixed.slice(0,9),check)||!validCheck(compositeOf(fixed),fixed[43]))continue;
      matches.add(fixed);
      // OCR mostly turns a digit into a look-alike letter (0->D, 8->B), so a swap
      // back to a digit after the leading letter is the likely correction.
      if(i>0&&/[A-Z]/.test(field[i])&&/[0-9]/.test(a))toDigit.add(fixed);
    }
    if(matches.size===1)return [...matches][0];
    if(toDigit.size===1)return [...toDigit][0];
    return line2;
  }
  function compositeOf(line2){return line2.slice(0,10)+digit(line2.slice(13,19))+line2[19]+digit(line2.slice(21,27))+line2.slice(27,43);}
  function findLines(text) {
    // A TD3 passport's MRZ has exactly two 44-character lines. Ignore every
    // other text block, including numbers printed elsewhere on the page.
    const original=String(text||'').split(/[\r\n]+/).map(clean).filter(x=>x.length>=24);
    const lines=[...original];
    // Some OCR engines return both MRZ lines as a single text line.
    for(const line of original){
      const at=line.indexOf('P<');
      if(at>=0 && line.length-at>=88){lines.push(line.slice(at,at+44),line.slice(at+44,at+88));}
    }
    const candidates=[];
    for(let i=0;i<lines.length;i++){
      const startInfo=line1Start(lines[i]);
      if(!startInfo)continue;
      const first=startInfo.text.slice(startInfo.at);
      const l1=normalizeNameFiller(first.slice(0,44).padEnd(44,'<'));
      if(first.length<32||!l1.includes('<<'))continue; // OCR may omit trailing filler characters
      const country=l1.slice(2,5).replace(/[0]/g,'O').replace(/[1]/g,'I');
      if(!/^[A-Z]{3}$/.test(country))continue;
      for(let j=i+1;j<Math.min(i+5,lines.length);j++){
        // Align the second line by its nationality field and check digits.
        for(const window2 of line2Windows(lines[j])){
          const line2=repairPassportNumber(window2);
          const name=l1.slice(5);
          if(!name.includes('<<'))continue;
          const checks={
            passport:validCheck(line2.slice(0,9),line2[9]),
            birth:validCheck(digit(line2.slice(13,19)),line2[19]),
            expiry:validCheck(digit(line2.slice(21,27)),line2[27])
          };
          const count=Object.values(checks).filter(Boolean).length;
          if(count<2)continue;
          const nationality=line2.slice(10,13).replace(/[0]/g,'O').replace(/[1]/g,'I');
          if(!/^[A-Z]{3}$/.test(nationality))continue;
          const composite=line2.slice(0,10)+digit(line2.slice(13,19))+line2[19]+digit(line2.slice(21,27))+line2.slice(27,43);
          const compositeValid=validCheck(composite,line2[43]);
          const score=count*30+Number(compositeValid)*13+Number(first.length===44)*5+Number(country===nationality)*4;
          candidates.push({line1:l1,line2,checks,count,compositeValid,score});
        }
      }
    }
    candidates.sort((a,b)=>b.score-a.score);
    return candidates[0]||null;
  }
  function parse(text, referenceDate) {
    const found=findLines(text);
    if(!found)throw new Error('No reliable passport MRZ found. Take a straight, sharp photo with both bottom MRZ lines fully visible.');
    const {line1:a,line2:b,checks,compositeValid}=found;
    const names=a.slice(5).split('<<');
    const last=(names[0]||'').replace(/<+/g,' ').trim();
    // OCR often mistakes trailing MRZ filler '<' for long runs of I/L/1.
    // These are not given names. Remove only suspicious long filler runs,
    // never short legitimate given-name characters.
    const rawGiven=names.slice(1).join(' ').replace(/<+/g,' ').trim();
    const first=rawGiven.split(/\s+/).filter(token=>{
      if(/(?:L|I|1|K|C){6,}/.test(token) && /(.)\1{3,}/.test(token))return false;
      if(/(.)\1{4,}/.test(token))return false;
      return true;
    }).map(token=>token.replace(/(?:L|I|1){5,}$/,'')).filter(Boolean).join(' ').trim();
    // Names contaminated by a fake filler run must be reviewed rather than
    // confidently filling OCR garbage into the passenger form.

    if(!/^[A-Z][A-Z ]{0,42}$/.test(last)|| (first&&!/^[A-Z][A-Z ]{0,42}$/.test(first)))throw new Error('MRZ name is unclear. Please retake the photo showing both machine-readable lines.');
    const nat=b.slice(10,13).replace(/0/g,'O').replace(/1/g,'I');
    const gender=b[20]==='M'?'Male':b[20]==='F'?'Female':'';
    const result={
      first, last,
      nationality:/^[A-Z]{3}$/.test(nat)?(NATIONALITIES[nat]||nat):'',
      gender,
      passport:checks.passport?b.slice(0,9).replace(/<+$/,''):'',
      dob:checks.birth?dateFromMRZ(b.slice(13,19),'birth',referenceDate):'',
      passportExpiry:checks.expiry?dateFromMRZ(b.slice(21,27),'expiry',referenceDate):'',
      checks,validated:Object.values(checks).filter(Boolean).length,
      compositeValid
    };
    if(!first&&!last)throw new Error('Could not read names. Try a sharper, well-lit passport photo.');
    return result;
  }
  // A name read from the MRZ may end with a letter that is really a misread
  // filler '<' (e.g. NURJAHAN< -> NURJAHANK). The MRZ has no check digit for
  // names, so compare with the printed (visual) part of the passport page:
  // drop up to three trailing look-alike letters only when the printed page
  // shows the shorter name and not the longer one. Real names such as MALIK
  // or THOMAS are kept because the printed page shows them in full.
  const SUSPECT_END=/^[A-Z]{2,}[KCLIXES]$/;
  function nameNeedsVisualCheck(result) {
    return [result.first,result.last].some(name=>String(name||'').split(' ').some(word=>SUSPECT_END.test(word)));
  }
  function confirmNamesWithVisual(result, visualText) {
    const words=new Set(String(visualText||'').toUpperCase().split(/[^A-Z]+/).filter(w=>w.length>=2));
    const fixed={...result};
    let review=false;
    for(const field of ['first','last']){
      fixed[field]=String(result[field]||'').split(' ').filter(Boolean).map(word=>{
        if(!SUSPECT_END.test(word)||words.has(word))return word;
        for(let cut=1;cut<=3&&word.length-cut>=2;cut++){
          const tail=word.slice(-cut),shorter=word.slice(0,-cut);
          if(!/^[KCLIXES]+$/.test(tail))break;
          if(words.has(shorter))return shorter;
        }
        review=true;
        return word;
      }).join(' ');
    }
    fixed.nameNeedsReview=review;
    return fixed;
  }
  let loadPromise;
  function loadEngine() {
    if(typeof document==='undefined')return Promise.reject(new Error('Passport scanner requires a browser.'));
    if(root.Tesseract?.createWorker)return Promise.resolve(root.Tesseract);
    if(loadPromise)return loadPromise;
    loadPromise=new Promise((resolve,reject)=>{
      const s=document.createElement('script');
      s.src='https://cdn.jsdelivr.net/npm/tesseract.js@5.1.1/dist/tesseract.min.js';
      s.async=true;s.crossOrigin='anonymous';
      s.onload=()=>root.Tesseract?.createWorker?resolve(root.Tesseract):reject(new Error('Passport OCR engine failed to initialize.'));
      s.onerror=()=>reject(new Error('Could not load the scanner. Check your internet connection and try again.'));
      document.head.appendChild(s);
    }).catch(e=>{loadPromise=null;throw e;});
    return loadPromise;
  }
  async function openImage(file) {
    if(!file||!file.type.startsWith('image/'))throw new Error('Please choose a passport photo (JPG, PNG, WEBP, or HEIC if supported).');
    if(file.size>15*1024*1024)throw new Error('Image exceeds 15 MB. Choose a smaller photo.');
    const url=URL.createObjectURL(file);
    try {return await new Promise((resolve,reject)=>{
      const image=new Image();image.onload=()=>resolve(image);image.onerror=()=>reject(new Error('Unable to open this image. Try JPG or PNG.'));image.src=url;
    });}finally{URL.revokeObjectURL(url);}
  }
  function makeCanvas(image,region,style='contrast',rotation=0) {
    // Work on the MRZ strip first, so non-MRZ text cannot contaminate results.
    // Only if that fails, try wider crops and rotated photos.
    const crops={lower:0.68,bottom:0.50,wide:0.30,full:0};
    const cut=rotation?0:(crops[region]??0.5);
    const srcY=Math.floor(image.height*cut),srcH=image.height-srcY;
    const scale=Math.min(2.2,2400/image.width);
    const width=Math.max(800,Math.round(image.width*scale));
    const height=Math.max(100,Math.round(srcH*scale));
    const c=document.createElement('canvas');
    const rotated=rotation===90||rotation===270;
    c.width=rotated?height:width;c.height=rotated?width:height;
    const ctx=c.getContext('2d',{willReadFrequently:true});
    if(!ctx)throw Error('Unable to process the image in this browser.');
    ctx.fillStyle='#fff';ctx.fillRect(0,0,c.width,c.height);
    ctx.save();
    if(rotation===180){ctx.translate(width,height);ctx.rotate(Math.PI);}
    if(rotation===90){ctx.translate(height,0);ctx.rotate(Math.PI/2);}
    if(rotation===270){ctx.translate(0,width);ctx.rotate(-Math.PI/2);}
    ctx.filter='grayscale(1) contrast('+(style==='threshold'?'2.2':'1.65')+')';
    ctx.drawImage(image,0,srcY,image.width,srcH,0,0,width,height);
    ctx.restore();
    if(style==='threshold'){
      const data=ctx.getImageData(0,0,c.width,c.height);
      const hist=new Uint32Array(256);let total=0;
      for(let i=0;i<data.data.length;i+=4){const v=data.data[i];hist[v]++;total+=v;}
      const size=c.width*c.height;let leftCount=0,leftSum=0,best=-1,level=145;
      for(let t=0;t<256;t++){
        leftCount+=hist[t];leftSum+=t*hist[t];if(!leftCount||leftCount===size)continue;
        const w0=leftCount/size,w1=1-w0;
        const m0=leftSum/leftCount,m1=(total-leftSum)/(size-leftCount);
        const between=w0*w1*(m0-m1)**2;
        if(between>best){best=between;level=t;}
      }
      for(let i=0;i<data.data.length;i+=4){const v=data.data[i]<level?0:255;data.data[i]=data.data[i+1]=data.data[i+2]=v;}
      ctx.putImageData(data,0,0);
    }
    return c;
  }
  // ---- Deskew --------------------------------------------------------------
  // OCR breaks tilted text lines into fragments, so straighten the photo first.
  // Classic projection method (no OCR needed): at the right angle, text rows
  // line up and the per-row ink totals become sharply peaked (high variance).
  function estimateSkew(image) {
    const scale=Math.min(1,900/Math.max(image.width,image.height));
    const w=Math.max(1,Math.round(image.width*scale)),h=Math.max(1,Math.round(image.height*scale));
    const c=document.createElement('canvas');c.width=w;c.height=h;
    const ctx=c.getContext('2d',{willReadFrequently:true});
    ctx.filter='grayscale(1)';ctx.drawImage(image,0,0,w,h);
    const px=ctx.getImageData(0,0,w,h).data;c.width=0;c.height=0;
    // Ink = clearly darker than its surroundings (local mean), so dark tables,
    // photos and shadows do not dominate; only strokes such as text count.
    const gray=new Float32Array(w*h);for(let i=0;i<w*h;i++)gray[i]=px[i*4];
    const integral=new Float64Array((w+1)*(h+1));
    for(let y=0;y<h;y++){let row=0;for(let x=0;x<w;x++){row+=gray[y*w+x];integral[(y+1)*(w+1)+x+1]=integral[y*(w+1)+x+1]+row;}}
    const r=Math.max(4,Math.round(Math.min(w,h)/40)),xs=[],ys=[];
    for(let y=0;y<h;y++)for(let x=0;x<w;x++){
      const x0=Math.max(0,x-r),x1=Math.min(w,x+r+1),y0=Math.max(0,y-r),y1=Math.min(h,y+r+1);
      const mean=(integral[y1*(w+1)+x1]-integral[y0*(w+1)+x1]-integral[y1*(w+1)+x0]+integral[y0*(w+1)+x0])/((x1-x0)*(y1-y0));
      if(gray[y*w+x]<mean*0.82){xs.push(x);ys.push(y);}
    }
    if(xs.length<200)return 0;
    const score=deg=>{
      const a=deg*Math.PI/180,s=Math.sin(a),co=Math.cos(a),bins=new Float64Array(h+w+2);
      for(let i=0;i<xs.length;i++){const v=Math.round(ys[i]*co-xs[i]*s)+w;if(v>=0&&v<bins.length)bins[v]++;}
      let sum=0;for(const b of bins)sum+=b*b;return sum;
    };
    let best=0,bestScore=-1;
    for(let d=-10;d<=10;d+=0.5){const sc=score(d);if(sc>bestScore){bestScore=sc;best=d;}}
    for(let d=best-0.4;d<=best+0.4;d+=0.1){const sc=score(d);if(sc>bestScore){bestScore=sc;best=d;}}
    return Math.round(best*10)/10;
  }
  function deskewed(image,deg) {
    const a=-deg*Math.PI/180,s=Math.abs(Math.sin(a)),co=Math.abs(Math.cos(a));
    const w=Math.round(image.width*co+image.height*s),h=Math.round(image.width*s+image.height*co);
    const c=document.createElement('canvas');c.width=w;c.height=h;
    const ctx=c.getContext('2d');
    ctx.fillStyle='#fff';ctx.fillRect(0,0,w,h);
    ctx.translate(w/2,h/2);ctx.rotate(a);ctx.drawImage(image,-image.width/2,-image.height/2);
    return c;
  }
  // ---- MRZ locator ---------------------------------------------------------
  // Phone photos rarely show the passport filling the frame: it may be small,
  // tilted, or upside down. Find the two MRZ lines first (rows full of '<' and
  // MRZ characters), measure their tilt, then cut them out, straighten them and
  // enlarge them so each character is ~45px tall before reading them again.
  function overviewCanvas(image,rotation) {
    const scale=Math.min(1,1800/Math.max(image.width,image.height));
    const w=Math.round(image.width*scale),h=Math.round(image.height*scale);
    const c=document.createElement('canvas');
    c.width=rotation===180?w:w;c.height=h;
    const ctx=c.getContext('2d');
    ctx.fillStyle='#fff';ctx.fillRect(0,0,w,h);
    if(rotation===180){ctx.translate(w,h);ctx.rotate(Math.PI);}
    ctx.filter='grayscale(1) contrast(1.5)';
    ctx.drawImage(image,0,0,w,h);
    return {canvas:c,scale};
  }
  function mrzLineBoxes(data) {
    const found=[];
    for(const block of data?.blocks||[])for(const para of block.paragraphs||[])for(const line of para.lines||[]){
      const text=clean(line.text||'');
      const fillers=(text.match(/</g)||[]).length;
      if(text.length>=20&&(fillers>=3||/P[<KCLXE][A-Z]{3}/.test(text)||/\d{6}[0-9<][MF<]\d{6}/.test(text)))found.push(line);
    }
    // The MRZ is the lowest pair of such lines.
    return found.sort((a,b)=>a.bbox.y0-b.bbox.y0).slice(-2);
  }
  function mrzCrops(image,lines,scale,rotation) {
    const x0=Math.min(...lines.map(l=>l.bbox.x0)),x1=Math.max(...lines.map(l=>l.bbox.x1));
    const y0=Math.min(...lines.map(l=>l.bbox.y0)),y1=Math.max(...lines.map(l=>l.bbox.y1));
    const lineH=lines.reduce((s,l)=>s+(l.bbox.y1-l.bbox.y0),0)/lines.length;
    // Tilt from the baselines (fallback: from the box corners).
    const angles=lines.map(l=>{const b=l.baseline;return b&&b.x1!==b.x0?Math.atan2(b.y1-b.y0,b.x1-b.x0):0;});
    const angle=angles.reduce((s,a)=>s+a,0)/angles.length;
    // Region in overview coordinates, widened for characters OCR missed at the ends.
    const padX=(x1-x0)*0.08+lineH,padY=lineH*1.2+Math.abs(Math.sin(angle))*(x1-x0)/2;
    const rx=Math.max(0,x0-padX),ry=Math.max(0,y0-padY),rw=(x1-x0)+padX*2,rh=(y1-y0)+padY*2;
    // Read from the full-resolution image: map overview -> original pixels.
    const toOrig=v=>v/scale;
    const target=Math.min(4,Math.max(1,45/(toOrig(lineH)||45)));
    const out=[];
    for(const style of ['contrast','threshold']){
      const c=document.createElement('canvas');
      c.width=Math.round(toOrig(rw)*target);c.height=Math.round(toOrig(rh)*target);
      const ctx=c.getContext('2d',{willReadFrequently:true});
      ctx.fillStyle='#fff';ctx.fillRect(0,0,c.width,c.height);
      ctx.filter='grayscale(1) contrast('+(style==='threshold'?'2':'1.6')+')';
      ctx.translate(c.width/2,c.height/2);ctx.rotate(-angle);ctx.scale(target,target);
      // Centre of the MRZ region in original pixels (undoing a 180° overview if used).
      let cx=toOrig(rx+rw/2),cy=toOrig(ry+rh/2);
      if(rotation===180){ctx.rotate(Math.PI);cx=image.width-cx;cy=image.height-cy;}
      ctx.drawImage(image,-cx,-cy);
      ctx.setTransform(1,0,0,1,0,0);
      if(style==='threshold')binarize(ctx,c);
      out.push(c);
    }
    return out;
  }
  function binarize(ctx,c) {
    const data=ctx.getImageData(0,0,c.width,c.height),px=data.data,hist=new Uint32Array(256);let total=0;
    for(let i=0;i<px.length;i+=4){hist[px[i]]++;total+=px[i];}
    const size=c.width*c.height;let leftCount=0,leftSum=0,best=-1,level=128;
    for(let t=0;t<256;t++){leftCount+=hist[t];leftSum+=t*hist[t];if(!leftCount||leftCount===size)continue;
      const w0=leftCount/size,w1=1-w0,m0=leftSum/leftCount,m1=(total-leftSum)/(size-leftCount),between=w0*w1*(m0-m1)**2;
      if(between>best){best=between;level=t;}}
    for(let i=0;i<px.length;i+=4){const v=px[i]<level?0:255;px[i]=px[i+1]=px[i+2]=v;}
    ctx.putImageData(data,0,0);
  }
  // Upper 72% of the page (the printed data), grayscale with mild contrast.
  function visualCanvas(image,contrast) {
    const scale=Math.min(2.2,2400/image.width);
    const c=document.createElement('canvas');
    c.width=Math.round(image.width*scale);c.height=Math.round(image.height*0.72*scale);
    const ctx=c.getContext('2d');
    if(!ctx)throw Error('Unable to process the image in this browser.');
    ctx.fillStyle='#fff';ctx.fillRect(0,0,c.width,c.height);
    ctx.filter='grayscale(1) contrast('+contrast+')';
    ctx.drawImage(image,0,0,image.width,image.height*0.72,0,0,c.width,c.height);
    return c;
  }
  // "Surname" / "Given Name(s)" labels found by OCR (tolerant of misreads like "I5urnarme").
  function nameLabels(data) {
    const words=[];
    for(const block of data?.blocks||[])for(const para of block.paragraphs||[])for(const line of para.lines||[])for(const word of line.words||[])words.push(word);
    return words.filter(w=>/(SUR|URN|RNAM|GIVE|IVEN)/i.test(w.text||'')&&w.bbox).slice(0,4);
  }
  // The area from a label down to the line under it, enlarged 2x. The label's
  // box can merge with the Bangla text beside it, so the area is generous.
  function stripBelow(canvas,bbox) {
    const h=Math.max(12,bbox.y1-bbox.y0);
    const x=Math.max(0,Math.round(bbox.x0-h*3)),y=Math.max(0,Math.round(bbox.y0-h*0.5));
    const w=Math.min(canvas.width-x,Math.round(canvas.width*0.45)),sh=Math.min(canvas.height-y,Math.round(h*3.5));
    const c=document.createElement('canvas');
    c.width=Math.max(1,w*2);c.height=Math.max(1,sh*2);
    const ctx=c.getContext('2d',{willReadFrequently:true});
    ctx.fillStyle='#fff';ctx.fillRect(0,0,c.width,c.height);
    if(w>0&&sh>0)ctx.drawImage(canvas,x,y,w,sh,0,0,c.width,c.height);
    // Printed data is the darkest ink on the page; labels and the security
    // pattern are lighter. Keep only pixels well below the typical background.
    const data=ctx.getImageData(0,0,c.width,c.height),px=data.data;
    const hist=new Uint32Array(256);
    for(let i=0;i<px.length;i+=4)hist[px[i]]++;
    const total=px.length/4;
    const at=share=>{let n=0;for(let v=0;v<256;v++){n+=hist[v];if(n>=total*share)return v;}return 255;};
    const dark=at(0.02),background=at(0.5);
    const level=dark+(background-dark)*0.45;
    for(let i=0;i<px.length;i+=4){const v=px[i]<level?0:255;px[i]=px[i+1]=px[i+2]=v;}
    ctx.putImageData(data,0,0);
    return c;
  }
  async function recognize(file, onProgress=()=>{}) {
    let image=await openImage(file);
    onProgress('Straightening the photo…');
    const skew=estimateSkew(image);
    if(Math.abs(skew)>=0.7)image=deskewed(image,skew);
    onProgress('Loading passport text scanner…');
    const engine=await loadEngine();
    const worker=await engine.createWorker('eng');
    let best=null,lastError=null;
    const passes=[
      ['lower','contrast',0,6,'Reading the two MRZ lines…'],
      ['locate','',0,0,'Locating the MRZ lines in the photo…'],
      ['locate','',180,0,'Checking photo orientation…'],
      ['bottom','threshold',0,6,'Enhancing the passport text…'],
      ['wide','contrast',0,6,'Checking a wider passport crop…'],
      ['full','threshold',0,11,'Searching the entire passport…'],
      ['full','contrast',180,6,'Checking photo orientation…'],
      ['full','contrast',90,6,'Checking sideways photo…'],
      ['full','contrast',270,6,'Checking sideways photo…']
    ];
    try {
      await worker.setParameters({tessedit_char_whitelist:'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789<',tessedit_pageseg_mode:'6'});
      const done=()=>best?.validated===3&&best?.compositeValid;
      const consider=text=>{
        try{
          const parsed=parse(text||'');
          if(!best||parsed.validated>best.validated||parsed.validated===best.validated&&parsed.compositeValid&&!best.compositeValid)best=parsed;
        }catch(e){lastError=e;}
      };
      for(const [region,style,rotation,psm,message] of passes){
        if(done())break;
        onProgress(message);
        if(region==='locate'){
          // Find the MRZ anywhere in the photo, then read a straightened, enlarged cut-out.
          const {canvas,scale}=overviewCanvas(image,rotation);
          let overview;
          try{await worker.setParameters({tessedit_pageseg_mode:'11'});overview=await worker.recognize(canvas,{},{text:true,blocks:true});}
          finally{canvas.width=0;canvas.height=0;}
          consider(overview.data?.text);
          const lines=mrzLineBoxes(overview.data);
          if(!lines.length||done())continue;
          await worker.setParameters({tessedit_pageseg_mode:'6'});
          for(const crop of mrzCrops(image,lines,scale,rotation)){
            if(done())break;
            try{consider((await worker.recognize(crop)).data?.text);}
            finally{crop.width=0;crop.height=0;}
          }
          continue;
        }
        const c=makeCanvas(image,region,style,rotation);
        let result;
        try{await worker.setParameters({tessedit_pageseg_mode:String(psm)});result=await worker.recognize(c);}
        finally{c.width=0;c.height=0;}
        consider(result.data?.text);
      }
      if(best&&nameNeedsVisualCheck(best)){
        // Read the printed upper part of the page to confirm the name. Mild
        // contrast and full-page layout (psm 3) read printed names best; the
        // printed page also has Bangla text and guilloche patterns, so no
        // character whitelist is used here.
        onProgress('Checking the printed name…');
        const mrzResult=best;
        // A separate worker: the MRZ worker keeps its character whitelist.
        let visualWorker=null;
        try{
          visualWorker=await engine.createWorker('eng');
          let seen='';
          for(const [contrast,psm] of [['1.3','3'],['1.65','11']]){
            const top=visualCanvas(image,contrast);
            try{
              await visualWorker.setParameters({tessedit_pageseg_mode:psm});
              const visual=await visualWorker.recognize(top,{},{text:true,blocks:true});
              seen+=' '+(visual.data?.text||'');
              // The printed name sits on the line under its label. Labels read
              // well; the name itself often does not on the patterned page, so
              // zoom into that one strip and read it as a single line.
              await visualWorker.setParameters({tessedit_pageseg_mode:'6'});
              for(const label of nameLabels(visual.data)){
                const strip=stripBelow(top,label.bbox);
                try{seen+=' '+((await visualWorker.recognize(strip)).data?.text||'');}
                finally{strip.width=0;strip.height=0;}
              }
              best=confirmNamesWithVisual(mrzResult,seen);
              if(!best.nameNeedsReview)break;
            }finally{top.width=0;top.height=0;}
          }
        }catch(e){/* keep the MRZ names; the user reviews them anyway */}
        finally{if(visualWorker)await visualWorker.terminate();}
      }
      if(best)return best;
      throw Error(lastError?.message||'No readable MRZ found. Upload a close-up of the two lines at the bottom of the passport page.');
    }finally{await worker.terminate();}
  }
  const api={parse,checkDigit,validCheck,dateFromMRZ,recognize,findLines,normalizeNameFiller,confirmNamesWithVisual,nameNeedsVisualCheck};
  root.ExplorePassportOCR=api;
  if(typeof module!=='undefined'&&module.exports)module.exports=api;
})(typeof window!=='undefined'?window:globalThis);
