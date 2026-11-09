/* Biz-Flights: compact airline-style A4 itinerary, matching the layout of the supplied ticket.
   Only genuine provider-issued PNR / confirmation fields may be shown as confirmed. */
(() => {
  'use strict';
  const W=595.28, H=841.89, L=14, R=581, navy='#1B395C', dark='#1B2735', muted='#626D7B';
  const light='#F2F5F8', gray='#D8DEE6', cream='#FFF8E7', bluePale='#E6F2F9', white='#FFFFFF', orange='#93611B';
  const palette={EK:'#D71920',QR:'#70183F',TK:'#D62828',BG:'#C52A36',SQ:'#A17F22',BA:'#154A81',EY:'#AF914E',MH:'#C43B37',AI:'#C42D38',WY:'#6F375A',CX:'#08665F',SV:'#145FA8',KU:'#145E8D',GF:'#9B8C58'};
  const sanitize=s=>String(s??'').normalize('NFKD').replace(/[\u0300-\u036f]/g,'').replace(/[\u2014\u2013]/g,'-').replace(/[\u2019]/g,"'").replace(/[^\x20-\x7e]/g,' ');
  const esc=s=>sanitize(s).replace(/\\/g,'\\\\').replace(/\(/g,'\\(').replace(/\)/g,'\\)');
  const rgb=s=>[1,3,5].map(i=>(parseInt(s.slice(i,i+2),16)/255).toFixed(4)).join(' ');
  const width=(s,z=9)=>[...sanitize(s)].reduce((a,c)=>a+z*(c===' '?0.29:/[MW@]/.test(c)?.83:/[il.,:;|!]/.test(c)?.25:/[A-Z0-9]/.test(c)?.64:.52),0);
  const fit=(s,z,max,min=5.8)=>{while(z>min&&width(s,z)>max)z-=.3;return Math.max(min,z)};
  function fmtDate(s){if(!s)return '-';const d=new Date(/^\d{4}-\d{2}-\d{2}$/.test(s)?s+'T12:00:00Z':s);return Number.isFinite(+d)?new Intl.DateTimeFormat('en-GB',{day:'2-digit',month:'short',year:'numeric',timeZone:'UTC'}).format(d):sanitize(s)}
  function fmtMoney(n,c='BDT'){return `${sanitize(c)} ${Number(n||0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2})}`}
  class PDF{
    constructor(){this.pages=[];this.images=[];this.newPage()}
    newPage(){this.cmd=[];this.pages.push(this.cmd)}
    emit(s){this.cmd.push(s)}
    rect(x,y,w,h,color,stroke){this.emit(`${rgb(color)} rg ${x.toFixed(2)} ${(H-y-h).toFixed(2)} ${w.toFixed(2)} ${h.toFixed(2)} re f`);if(stroke){this.emit(`${rgb(stroke)} RG .5 w ${x.toFixed(2)} ${(H-y-h).toFixed(2)} ${w.toFixed(2)} ${h.toFixed(2)} re S`)}}
    line(x,y,x2,y2,color=gray,thick=.55){this.emit(`${rgb(color)} RG ${thick} w ${x.toFixed(2)} ${(H-y).toFixed(2)} m ${x2.toFixed(2)} ${(H-y2).toFixed(2)} l S`)}
    txt(v,x,y,size=8,color=dark,bold=false){this.emit(`BT /F${bold?2:1} ${size.toFixed(2)} Tf ${rgb(color)} rg 1 0 0 1 ${x.toFixed(2)} ${(H-y).toFixed(2)} Tm (${esc(v)}) Tj ET`)}
    right(v,x,y,size=8,color=dark,bold=false){this.txt(v,x-width(v,size),y,size,color,bold)}
    wrapped(v,x,y,max,z=7.4,lh=10,color=dark,maxLines=4){let line='',out=[];for(const word of sanitize(v).split(/\s+/)){const t=line?line+' '+word:word;if(line&&width(t,z)>max){out.push(line);line=word;}else line=t}if(line)out.push(line);out.slice(0,maxLines).forEach((s,i)=>this.txt(s,x,y+i*lh,z,color));return Math.min(out.length,maxLines)*lh}
    image(jpeg,x,y,w,h){
      const n=this.images.push(jpeg);
      this.emit(`q ${w.toFixed(2)} 0 0 ${h.toFixed(2)} ${x.toFixed(2)} ${(H-y-h).toFixed(2)} cm /Im${n} Do Q`);
    }
    // Keep genuine airline marks in their original aspect ratio (no stretching).
    fittedImage(jpeg,x,y,w,h){
      if(!jpeg?.width||!jpeg?.height)return;
      const ratio=Math.min(w/jpeg.width,h/jpeg.height),iw=jpeg.width*ratio,ih=jpeg.height*ratio;
      this.image(jpeg,x+(w-iw)/2,y+(h-ih)/2,iw,ih);
    }
    file(){
      const enc=new TextEncoder(), parts=[];let totalBytes=0;
      const append=v=>{const bytes=typeof v==='string'?enc.encode(v):v;parts.push(bytes);totalBytes+=bytes.length};
      const pageCount=this.pages.length, imageStart=5+2*pageCount;
      const numObjects=4+2*pageCount+this.images.length;
      const offsets=new Array(numObjects+1).fill(0);
      const object=(id,contents)=>{offsets[id]=totalBytes;append(`${id} 0 obj\n`);append(contents);append('\nendobj\n')};
      append('%PDF-1.4\n%BizFlights\n');
      object(1,'<< /Type /Catalog /Pages 2 0 R >>');
      object(2,`<< /Type /Pages /Kids [${this.pages.map((_,i)=>`${5+i*2} 0 R`).join(' ')}] /Count ${pageCount} >>`);
      object(3,'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>');
      object(4,'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>');
      const xobjects=this.images.map((_,i)=>`/Im${i+1} ${imageStart+i} 0 R`).join(' ');
      for(let i=0;i<this.pages.length;i++){
        const id=5+i*2,stream=enc.encode(this.pages[i].join('\n')+'\n');
        object(id,`<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ${W} ${H}] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> /XObject << ${xobjects} >> >> /Contents ${id+1} 0 R >>`);
        offsets[id+1]=totalBytes;append(`${id+1} 0 obj\n<< /Length ${stream.length} >>\nstream\n`);append(stream);append('endstream\nendobj\n');
      }
      for(let i=0;i<this.images.length;i++){
        const id=imageStart+i,img=this.images[i];offsets[id]=totalBytes;
        append(`${id} 0 obj\n<< /Type /XObject /Subtype /Image /Width ${img.width} /Height ${img.height} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ${img.bytes.length} >>\nstream\n`);
        append(img.bytes);append('\nendstream\nendobj\n');
      }
      const xref=totalBytes;append(`xref\n0 ${numObjects+1}\n0000000000 65535 f \n`);
      for(let i=1;i<=numObjects;i++)append(`${String(offsets[i]).padStart(10,'0')} 00000 n \n`);
      append(`trailer\n<< /Size ${numObjects+1} /Root 1 0 R >>\nstartxref\n${xref}\n%%EOF\n`);
      return new Blob(parts,{type:'application/pdf'});
    }
  }
  async function loadJpeg(url){
    // Use same-origin routes to avoid canvas CORS issues. No passenger data is sent.
    // Fetch first so HTML errors or missing logo assets cannot be mistaken for images.
    const controller=new AbortController();const timeout=setTimeout(()=>controller.abort(),14000);
    try{
      const response=await fetch(url,{signal:controller.signal,cache:'no-cache'});
      if(!response.ok||!/^image\/(png|jpeg)/i.test(response.headers.get('content-type')||''))return null;
      const blob=await response.blob();
      const objectUrl=URL.createObjectURL(blob);
      try{
        const image=new Image();image.src=objectUrl;await image.decode();
        if(!image.naturalWidth||!image.naturalHeight) return null;
        const canvas=document.createElement('canvas');
        canvas.width=image.naturalWidth;canvas.height=image.naturalHeight;
        const ctx=canvas.getContext('2d');if(!ctx)return null;
        ctx.fillStyle='#FFFFFF';ctx.fillRect(0,0,canvas.width,canvas.height);ctx.drawImage(image,0,0);
        const data=canvas.toDataURL('image/jpeg',.96).split(',')[1];
        const raw=atob(data),bytes=new Uint8Array(raw.length);for(let i=0;i<raw.length;i++)bytes[i]=raw.charCodeAt(i);
        return {width:canvas.width,height:canvas.height,bytes};
      }finally{URL.revokeObjectURL(objectUrl)}
    }catch{return null}finally{clearTimeout(timeout)}
  }
/* Local QR encoder adapted from Kazuhiko Arase QRCode implementation (MIT license), bundled by Biz-Flights. */
const ExploreQR=(function(){const factories={
"QR8bitByte":function(require,module,exports){
var QRMode = require('./QRMode');

function QR8bitByte(data) {
	this.mode = QRMode.MODE_8BIT_BYTE;
	this.data = data;
}

QR8bitByte.prototype = {

	getLength : function() {
		return this.data.length;
	},
	
	write : function(buffer) {
		for (var i = 0; i < this.data.length; i++) {
			// not JIS ...
			buffer.put(this.data.charCodeAt(i), 8);
		}
	}
};

module.exports = QR8bitByte;

},
"QRBitBuffer":function(require,module,exports){
function QRBitBuffer() {
	this.buffer = [];
	this.length = 0;
}

QRBitBuffer.prototype = {

	get : function(index) {
		var bufIndex = Math.floor(index / 8);
		return ( (this.buffer[bufIndex] >>> (7 - index % 8) ) & 1) == 1;
	},
	
	put : function(num, length) {
		for (var i = 0; i < length; i++) {
			this.putBit( ( (num >>> (length - i - 1) ) & 1) == 1);
		}
	},
	
	getLengthInBits : function() {
		return this.length;
	},
	
	putBit : function(bit) {
	
		var bufIndex = Math.floor(this.length / 8);
		if (this.buffer.length <= bufIndex) {
			this.buffer.push(0);
		}
	
		if (bit) {
			this.buffer[bufIndex] |= (0x80 >>> (this.length % 8) );
		}
	
		this.length++;
	}
};

module.exports = QRBitBuffer;

},
"QRErrorCorrectLevel":function(require,module,exports){
module.exports = {
	L : 1,
	M : 0,
	Q : 3,
	H : 2
};


},
"QRMaskPattern":function(require,module,exports){
module.exports = {
	PATTERN000 : 0,
	PATTERN001 : 1,
	PATTERN010 : 2,
	PATTERN011 : 3,
	PATTERN100 : 4,
	PATTERN101 : 5,
	PATTERN110 : 6,
	PATTERN111 : 7
};

},
"QRMath":function(require,module,exports){
var QRMath = {

	glog : function(n) {
	
		if (n < 1) {
			throw new Error("glog(" + n + ")");
		}
		
		return QRMath.LOG_TABLE[n];
	},
	
	gexp : function(n) {
	
		while (n < 0) {
			n += 255;
		}
	
		while (n >= 256) {
			n -= 255;
		}
	
		return QRMath.EXP_TABLE[n];
	},
	
	EXP_TABLE : new Array(256),
	
	LOG_TABLE : new Array(256)

};
	
for (var i = 0; i < 8; i++) {
	QRMath.EXP_TABLE[i] = 1 << i;
}
for (var i = 8; i < 256; i++) {
	QRMath.EXP_TABLE[i] = QRMath.EXP_TABLE[i - 4]
		^ QRMath.EXP_TABLE[i - 5]
		^ QRMath.EXP_TABLE[i - 6]
		^ QRMath.EXP_TABLE[i - 8];
}
for (var i = 0; i < 255; i++) {
	QRMath.LOG_TABLE[QRMath.EXP_TABLE[i] ] = i;
}

module.exports = QRMath;

},
"QRMode":function(require,module,exports){
module.exports = {
    MODE_NUMBER :       1 << 0,
    MODE_ALPHA_NUM :    1 << 1,
    MODE_8BIT_BYTE :    1 << 2,
    MODE_KANJI :        1 << 3
};

},
"QRPolynomial":function(require,module,exports){
var QRMath = require('./QRMath');

function QRPolynomial(num, shift) {
	if (num.length === undefined) {
		throw new Error(num.length + "/" + shift);
	}

	var offset = 0;

	while (offset < num.length && num[offset] === 0) {
		offset++;
	}

	this.num = new Array(num.length - offset + shift);
	for (var i = 0; i < num.length - offset; i++) {
		this.num[i] = num[i + offset];
	}
}

QRPolynomial.prototype = {

	get : function(index) {
		return this.num[index];
	},
	
	getLength : function() {
		return this.num.length;
	},
	
	multiply : function(e) {
	
		var num = new Array(this.getLength() + e.getLength() - 1);
	
		for (var i = 0; i < this.getLength(); i++) {
			for (var j = 0; j < e.getLength(); j++) {
				num[i + j] ^= QRMath.gexp(QRMath.glog(this.get(i) ) + QRMath.glog(e.get(j) ) );
			}
		}
	
		return new QRPolynomial(num, 0);
	},
	
	mod : function(e) {
	
		if (this.getLength() - e.getLength() < 0) {
			return this;
		}
	
		var ratio = QRMath.glog(this.get(0) ) - QRMath.glog(e.get(0) );
	
		var num = new Array(this.getLength() );
		
		for (var i = 0; i < this.getLength(); i++) {
			num[i] = this.get(i);
		}
		
		for (var x = 0; x < e.getLength(); x++) {
			num[x] ^= QRMath.gexp(QRMath.glog(e.get(x) ) + ratio);
		}
	
		// recursive call
		return new QRPolynomial(num, 0).mod(e);
	}
};

module.exports = QRPolynomial;

},
"QRRSBlock":function(require,module,exports){
var QRErrorCorrectLevel = require('./QRErrorCorrectLevel');

function QRRSBlock(totalCount, dataCount) {
	this.totalCount = totalCount;
	this.dataCount  = dataCount;
}

QRRSBlock.RS_BLOCK_TABLE = [

	// L
	// M
	// Q
	// H

	// 1
	[1, 26, 19],
	[1, 26, 16],
	[1, 26, 13],
	[1, 26, 9],
	
	// 2
	[1, 44, 34],
	[1, 44, 28],
	[1, 44, 22],
	[1, 44, 16],

	// 3
	[1, 70, 55],
	[1, 70, 44],
	[2, 35, 17],
	[2, 35, 13],

	// 4		
	[1, 100, 80],
	[2, 50, 32],
	[2, 50, 24],
	[4, 25, 9],
	
	// 5
	[1, 134, 108],
	[2, 67, 43],
	[2, 33, 15, 2, 34, 16],
	[2, 33, 11, 2, 34, 12],
	
	// 6
	[2, 86, 68],
	[4, 43, 27],
	[4, 43, 19],
	[4, 43, 15],
	
	// 7		
	[2, 98, 78],
	[4, 49, 31],
	[2, 32, 14, 4, 33, 15],
	[4, 39, 13, 1, 40, 14],
	
	// 8
	[2, 121, 97],
	[2, 60, 38, 2, 61, 39],
	[4, 40, 18, 2, 41, 19],
	[4, 40, 14, 2, 41, 15],
	
	// 9
	[2, 146, 116],
	[3, 58, 36, 2, 59, 37],
	[4, 36, 16, 4, 37, 17],
	[4, 36, 12, 4, 37, 13],
	
	// 10		
	[2, 86, 68, 2, 87, 69],
	[4, 69, 43, 1, 70, 44],
	[6, 43, 19, 2, 44, 20],
	[6, 43, 15, 2, 44, 16],

	// 11
	[4, 101, 81],
	[1, 80, 50, 4, 81, 51],
	[4, 50, 22, 4, 51, 23],
	[3, 36, 12, 8, 37, 13],

	// 12
	[2, 116, 92, 2, 117, 93],
	[6, 58, 36, 2, 59, 37],
	[4, 46, 20, 6, 47, 21],
	[7, 42, 14, 4, 43, 15],

	// 13
	[4, 133, 107],
	[8, 59, 37, 1, 60, 38],
	[8, 44, 20, 4, 45, 21],
	[12, 33, 11, 4, 34, 12],

	// 14
	[3, 145, 115, 1, 146, 116],
	[4, 64, 40, 5, 65, 41],
	[11, 36, 16, 5, 37, 17],
	[11, 36, 12, 5, 37, 13],

	// 15
	[5, 109, 87, 1, 110, 88],
	[5, 65, 41, 5, 66, 42],
	[5, 54, 24, 7, 55, 25],
	[11, 36, 12],

	// 16
	[5, 122, 98, 1, 123, 99],
	[7, 73, 45, 3, 74, 46],
	[15, 43, 19, 2, 44, 20],
	[3, 45, 15, 13, 46, 16],

	// 17
	[1, 135, 107, 5, 136, 108],
	[10, 74, 46, 1, 75, 47],
	[1, 50, 22, 15, 51, 23],
	[2, 42, 14, 17, 43, 15],

	// 18
	[5, 150, 120, 1, 151, 121],
	[9, 69, 43, 4, 70, 44],
	[17, 50, 22, 1, 51, 23],
	[2, 42, 14, 19, 43, 15],

	// 19
	[3, 141, 113, 4, 142, 114],
	[3, 70, 44, 11, 71, 45],
	[17, 47, 21, 4, 48, 22],
	[9, 39, 13, 16, 40, 14],

	// 20
	[3, 135, 107, 5, 136, 108],
	[3, 67, 41, 13, 68, 42],
	[15, 54, 24, 5, 55, 25],
	[15, 43, 15, 10, 44, 16],

	// 21
	[4, 144, 116, 4, 145, 117],
	[17, 68, 42],
	[17, 50, 22, 6, 51, 23],
	[19, 46, 16, 6, 47, 17],

	// 22
	[2, 139, 111, 7, 140, 112],
	[17, 74, 46],
	[7, 54, 24, 16, 55, 25],
	[34, 37, 13],

	// 23
	[4, 151, 121, 5, 152, 122],
	[4, 75, 47, 14, 76, 48],
	[11, 54, 24, 14, 55, 25],
	[16, 45, 15, 14, 46, 16],

	// 24
	[6, 147, 117, 4, 148, 118],
	[6, 73, 45, 14, 74, 46],
	[11, 54, 24, 16, 55, 25],
	[30, 46, 16, 2, 47, 17],

	// 25
	[8, 132, 106, 4, 133, 107],
	[8, 75, 47, 13, 76, 48],
	[7, 54, 24, 22, 55, 25],
	[22, 45, 15, 13, 46, 16],

	// 26
	[10, 142, 114, 2, 143, 115],
	[19, 74, 46, 4, 75, 47],
	[28, 50, 22, 6, 51, 23],
	[33, 46, 16, 4, 47, 17],

	// 27
	[8, 152, 122, 4, 153, 123],
	[22, 73, 45, 3, 74, 46],
	[8, 53, 23, 26, 54, 24],
	[12, 45, 15, 28, 46, 16],

	// 28
	[3, 147, 117, 10, 148, 118],
	[3, 73, 45, 23, 74, 46],
	[4, 54, 24, 31, 55, 25],
	[11, 45, 15, 31, 46, 16],

	// 29
	[7, 146, 116, 7, 147, 117],
	[21, 73, 45, 7, 74, 46],
	[1, 53, 23, 37, 54, 24],
	[19, 45, 15, 26, 46, 16],

	// 30
	[5, 145, 115, 10, 146, 116],
	[19, 75, 47, 10, 76, 48],
	[15, 54, 24, 25, 55, 25],
	[23, 45, 15, 25, 46, 16],

	// 31
	[13, 145, 115, 3, 146, 116],
	[2, 74, 46, 29, 75, 47],
	[42, 54, 24, 1, 55, 25],
	[23, 45, 15, 28, 46, 16],

	// 32
	[17, 145, 115],
	[10, 74, 46, 23, 75, 47],
	[10, 54, 24, 35, 55, 25],
	[19, 45, 15, 35, 46, 16],

	// 33
	[17, 145, 115, 1, 146, 116],
	[14, 74, 46, 21, 75, 47],
	[29, 54, 24, 19, 55, 25],
	[11, 45, 15, 46, 46, 16],

	// 34
	[13, 145, 115, 6, 146, 116],
	[14, 74, 46, 23, 75, 47],
	[44, 54, 24, 7, 55, 25],
	[59, 46, 16, 1, 47, 17],

	// 35
	[12, 151, 121, 7, 152, 122],
	[12, 75, 47, 26, 76, 48],
	[39, 54, 24, 14, 55, 25],
	[22, 45, 15, 41, 46, 16],

	// 36
	[6, 151, 121, 14, 152, 122],
	[6, 75, 47, 34, 76, 48],
	[46, 54, 24, 10, 55, 25],
	[2, 45, 15, 64, 46, 16],

	// 37
	[17, 152, 122, 4, 153, 123],
	[29, 74, 46, 14, 75, 47],
	[49, 54, 24, 10, 55, 25],
	[24, 45, 15, 46, 46, 16],

	// 38
	[4, 152, 122, 18, 153, 123],
	[13, 74, 46, 32, 75, 47],
	[48, 54, 24, 14, 55, 25],
	[42, 45, 15, 32, 46, 16],

	// 39
	[20, 147, 117, 4, 148, 118],
	[40, 75, 47, 7, 76, 48],
	[43, 54, 24, 22, 55, 25],
	[10, 45, 15, 67, 46, 16],

	// 40
	[19, 148, 118, 6, 149, 119],
	[18, 75, 47, 31, 76, 48],
	[34, 54, 24, 34, 55, 25],
	[20, 45, 15, 61, 46, 16]
];

QRRSBlock.getRSBlocks = function(typeNumber, errorCorrectLevel) {
	
	var rsBlock = QRRSBlock.getRsBlockTable(typeNumber, errorCorrectLevel);
	
	if (rsBlock === undefined) {
		throw new Error("bad rs block @ typeNumber:" + typeNumber + "/errorCorrectLevel:" + errorCorrectLevel);
	}

	var length = rsBlock.length / 3;
	
	var list = [];
	
	for (var i = 0; i < length; i++) {

		var count = rsBlock[i * 3 + 0];
		var totalCount = rsBlock[i * 3 + 1];
		var dataCount  = rsBlock[i * 3 + 2];

		for (var j = 0; j < count; j++) {
			list.push(new QRRSBlock(totalCount, dataCount) );	
		}
	}
	
	return list;
};

QRRSBlock.getRsBlockTable = function(typeNumber, errorCorrectLevel) {

	switch(errorCorrectLevel) {
	case QRErrorCorrectLevel.L :
		return QRRSBlock.RS_BLOCK_TABLE[(typeNumber - 1) * 4 + 0];
	case QRErrorCorrectLevel.M :
		return QRRSBlock.RS_BLOCK_TABLE[(typeNumber - 1) * 4 + 1];
	case QRErrorCorrectLevel.Q :
		return QRRSBlock.RS_BLOCK_TABLE[(typeNumber - 1) * 4 + 2];
	case QRErrorCorrectLevel.H :
		return QRRSBlock.RS_BLOCK_TABLE[(typeNumber - 1) * 4 + 3];
	default :
		return undefined;
	}
};

module.exports = QRRSBlock;

},
"QRUtil":function(require,module,exports){
var QRMode = require('./QRMode');
var QRPolynomial = require('./QRPolynomial');
var QRMath = require('./QRMath');
var QRMaskPattern = require('./QRMaskPattern');

var QRUtil = {

    PATTERN_POSITION_TABLE : [
        [],
        [6, 18],
        [6, 22],
        [6, 26],
        [6, 30],
        [6, 34],
        [6, 22, 38],
        [6, 24, 42],
        [6, 26, 46],
        [6, 28, 50],
        [6, 30, 54],        
        [6, 32, 58],
        [6, 34, 62],
        [6, 26, 46, 66],
        [6, 26, 48, 70],
        [6, 26, 50, 74],
        [6, 30, 54, 78],
        [6, 30, 56, 82],
        [6, 30, 58, 86],
        [6, 34, 62, 90],
        [6, 28, 50, 72, 94],
        [6, 26, 50, 74, 98],
        [6, 30, 54, 78, 102],
        [6, 28, 54, 80, 106],
        [6, 32, 58, 84, 110],
        [6, 30, 58, 86, 114],
        [6, 34, 62, 90, 118],
        [6, 26, 50, 74, 98, 122],
        [6, 30, 54, 78, 102, 126],
        [6, 26, 52, 78, 104, 130],
        [6, 30, 56, 82, 108, 134],
        [6, 34, 60, 86, 112, 138],
        [6, 30, 58, 86, 114, 142],
        [6, 34, 62, 90, 118, 146],
        [6, 30, 54, 78, 102, 126, 150],
        [6, 24, 50, 76, 102, 128, 154],
        [6, 28, 54, 80, 106, 132, 158],
        [6, 32, 58, 84, 110, 136, 162],
        [6, 26, 54, 82, 110, 138, 166],
        [6, 30, 58, 86, 114, 142, 170]
    ],

    G15 : (1 << 10) | (1 << 8) | (1 << 5) | (1 << 4) | (1 << 2) | (1 << 1) | (1 << 0),
    G18 : (1 << 12) | (1 << 11) | (1 << 10) | (1 << 9) | (1 << 8) | (1 << 5) | (1 << 2) | (1 << 0),
    G15_MASK : (1 << 14) | (1 << 12) | (1 << 10)    | (1 << 4) | (1 << 1),

    getBCHTypeInfo : function(data) {
        var d = data << 10;
        while (QRUtil.getBCHDigit(d) - QRUtil.getBCHDigit(QRUtil.G15) >= 0) {
            d ^= (QRUtil.G15 << (QRUtil.getBCHDigit(d) - QRUtil.getBCHDigit(QRUtil.G15) ) );    
        }
        return ( (data << 10) | d) ^ QRUtil.G15_MASK;
    },

    getBCHTypeNumber : function(data) {
        var d = data << 12;
        while (QRUtil.getBCHDigit(d) - QRUtil.getBCHDigit(QRUtil.G18) >= 0) {
            d ^= (QRUtil.G18 << (QRUtil.getBCHDigit(d) - QRUtil.getBCHDigit(QRUtil.G18) ) );    
        }
        return (data << 12) | d;
    },

    getBCHDigit : function(data) {

        var digit = 0;

        while (data !== 0) {
            digit++;
            data >>>= 1;
        }

        return digit;
    },

    getPatternPosition : function(typeNumber) {
        return QRUtil.PATTERN_POSITION_TABLE[typeNumber - 1];
    },

    getMask : function(maskPattern, i, j) {
        
        switch (maskPattern) {
            
        case QRMaskPattern.PATTERN000 : return (i + j) % 2 === 0;
        case QRMaskPattern.PATTERN001 : return i % 2 === 0;
        case QRMaskPattern.PATTERN010 : return j % 3 === 0;
        case QRMaskPattern.PATTERN011 : return (i + j) % 3 === 0;
        case QRMaskPattern.PATTERN100 : return (Math.floor(i / 2) + Math.floor(j / 3) ) % 2 === 0;
        case QRMaskPattern.PATTERN101 : return (i * j) % 2 + (i * j) % 3 === 0;
        case QRMaskPattern.PATTERN110 : return ( (i * j) % 2 + (i * j) % 3) % 2 === 0;
        case QRMaskPattern.PATTERN111 : return ( (i * j) % 3 + (i + j) % 2) % 2 === 0;

        default :
            throw new Error("bad maskPattern:" + maskPattern);
        }
    },

    getErrorCorrectPolynomial : function(errorCorrectLength) {

        var a = new QRPolynomial([1], 0);

        for (var i = 0; i < errorCorrectLength; i++) {
            a = a.multiply(new QRPolynomial([1, QRMath.gexp(i)], 0) );
        }

        return a;
    },

    getLengthInBits : function(mode, type) {

        if (1 <= type && type < 10) {

            // 1 - 9

            switch(mode) {
            case QRMode.MODE_NUMBER     : return 10;
            case QRMode.MODE_ALPHA_NUM  : return 9;
            case QRMode.MODE_8BIT_BYTE  : return 8;
            case QRMode.MODE_KANJI      : return 8;
            default :
                throw new Error("mode:" + mode);
            }

        } else if (type < 27) {

            // 10 - 26

            switch(mode) {
            case QRMode.MODE_NUMBER     : return 12;
            case QRMode.MODE_ALPHA_NUM  : return 11;
            case QRMode.MODE_8BIT_BYTE  : return 16;
            case QRMode.MODE_KANJI      : return 10;
            default :
                throw new Error("mode:" + mode);
            }

        } else if (type < 41) {

            // 27 - 40

            switch(mode) {
            case QRMode.MODE_NUMBER     : return 14;
            case QRMode.MODE_ALPHA_NUM  : return 13;
            case QRMode.MODE_8BIT_BYTE  : return 16;
            case QRMode.MODE_KANJI      : return 12;
            default :
                throw new Error("mode:" + mode);
            }

        } else {
            throw new Error("type:" + type);
        }
    },

    getLostPoint : function(qrCode) {
        
        var moduleCount = qrCode.getModuleCount();
        var lostPoint = 0;
        var row = 0; 
        var col = 0;

        
        // LEVEL1
        
        for (row = 0; row < moduleCount; row++) {

            for (col = 0; col < moduleCount; col++) {

                var sameCount = 0;
                var dark = qrCode.isDark(row, col);

                for (var r = -1; r <= 1; r++) {

                    if (row + r < 0 || moduleCount <= row + r) {
                        continue;
                    }

                    for (var c = -1; c <= 1; c++) {

                        if (col + c < 0 || moduleCount <= col + c) {
                            continue;
                        }

                        if (r === 0 && c === 0) {
                            continue;
                        }

                        if (dark === qrCode.isDark(row + r, col + c) ) {
                            sameCount++;
                        }
                    }
                }

                if (sameCount > 5) {
                    lostPoint += (3 + sameCount - 5);
                }
            }
        }

        // LEVEL2

        for (row = 0; row < moduleCount - 1; row++) {
            for (col = 0; col < moduleCount - 1; col++) {
                var count = 0;
                if (qrCode.isDark(row,     col    ) ) count++;
                if (qrCode.isDark(row + 1, col    ) ) count++;
                if (qrCode.isDark(row,     col + 1) ) count++;
                if (qrCode.isDark(row + 1, col + 1) ) count++;
                if (count === 0 || count === 4) {
                    lostPoint += 3;
                }
            }
        }

        // LEVEL3

        for (row = 0; row < moduleCount; row++) {
            for (col = 0; col < moduleCount - 6; col++) {
                if (qrCode.isDark(row, col) && 
                        !qrCode.isDark(row, col + 1) && 
                         qrCode.isDark(row, col + 2) && 
                         qrCode.isDark(row, col + 3) && 
                         qrCode.isDark(row, col + 4) && 
                        !qrCode.isDark(row, col + 5) && 
                         qrCode.isDark(row, col + 6) ) {
                    lostPoint += 40;
                }
            }
        }

        for (col = 0; col < moduleCount; col++) {
            for (row = 0; row < moduleCount - 6; row++) {
                if (qrCode.isDark(row, col) &&
                        !qrCode.isDark(row + 1, col) &&
                         qrCode.isDark(row + 2, col) &&
                         qrCode.isDark(row + 3, col) &&
                         qrCode.isDark(row + 4, col) &&
                        !qrCode.isDark(row + 5, col) &&
                         qrCode.isDark(row + 6, col) ) {
                    lostPoint += 40;
                }
            }
        }

        // LEVEL4
        
        var darkCount = 0;

        for (col = 0; col < moduleCount; col++) {
            for (row = 0; row < moduleCount; row++) {
                if (qrCode.isDark(row, col) ) {
                    darkCount++;
                }
            }
        }
        
        var ratio = Math.abs(100 * darkCount / moduleCount / moduleCount - 50) / 5;
        lostPoint += ratio * 10;

        return lostPoint;       
    }

};

module.exports = QRUtil;

},
"index":function(require,module,exports){
//---------------------------------------------------------------------
// QRCode for JavaScript
//
// Copyright (c) 2009 Kazuhiko Arase
//
// URL: http://www.d-project.com/
//
// Licensed under the MIT license:
//   http://www.opensource.org/licenses/mit-license.php
//
// The word "QR Code" is registered trademark of 
// DENSO WAVE INCORPORATED
//   http://www.denso-wave.com/qrcode/faqpatent-e.html
//
//---------------------------------------------------------------------
// Modified to work in node for this project (and some refactoring)
//---------------------------------------------------------------------

var QR8bitByte = require('./QR8bitByte');
var QRUtil = require('./QRUtil');
var QRPolynomial = require('./QRPolynomial');
var QRRSBlock = require('./QRRSBlock');
var QRBitBuffer = require('./QRBitBuffer');

function QRCode(typeNumber, errorCorrectLevel) {
	this.typeNumber = typeNumber;
	this.errorCorrectLevel = errorCorrectLevel;
	this.modules = null;
	this.moduleCount = 0;
	this.dataCache = null;
	this.dataList = [];
}

QRCode.prototype = {
	
	addData : function(data) {
		var newData = new QR8bitByte(data);
		this.dataList.push(newData);
		this.dataCache = null;
	},
	
	isDark : function(row, col) {
		if (row < 0 || this.moduleCount <= row || col < 0 || this.moduleCount <= col) {
			throw new Error(row + "," + col);
		}
		return this.modules[row][col];
	},

	getModuleCount : function() {
		return this.moduleCount;
	},
	
	make : function() {
		// Calculate automatically typeNumber if provided is < 1
		if (this.typeNumber < 1 ){
			var typeNumber = 1;
			for (typeNumber = 1; typeNumber < 40; typeNumber++) {
				var rsBlocks = QRRSBlock.getRSBlocks(typeNumber, this.errorCorrectLevel);

				var buffer = new QRBitBuffer();
				var totalDataCount = 0;
				for (var i = 0; i < rsBlocks.length; i++) {
					totalDataCount += rsBlocks[i].dataCount;
				}

				for (var x = 0; x < this.dataList.length; x++) {
					var data = this.dataList[x];
					buffer.put(data.mode, 4);
					buffer.put(data.getLength(), QRUtil.getLengthInBits(data.mode, typeNumber) );
					data.write(buffer);
				}
				if (buffer.getLengthInBits() <= totalDataCount * 8)
					break;
			}
			this.typeNumber = typeNumber;
		}
		this.makeImpl(false, this.getBestMaskPattern() );
	},
	
	makeImpl : function(test, maskPattern) {
		
		this.moduleCount = this.typeNumber * 4 + 17;
		this.modules = new Array(this.moduleCount);
		
		for (var row = 0; row < this.moduleCount; row++) {
			
			this.modules[row] = new Array(this.moduleCount);
			
			for (var col = 0; col < this.moduleCount; col++) {
				this.modules[row][col] = null;//(col + row) % 3;
			}
		}
	
		this.setupPositionProbePattern(0, 0);
		this.setupPositionProbePattern(this.moduleCount - 7, 0);
		this.setupPositionProbePattern(0, this.moduleCount - 7);
		this.setupPositionAdjustPattern();
		this.setupTimingPattern();
		this.setupTypeInfo(test, maskPattern);
		
		if (this.typeNumber >= 7) {
			this.setupTypeNumber(test);
		}
	
		if (this.dataCache === null) {
			this.dataCache = QRCode.createData(this.typeNumber, this.errorCorrectLevel, this.dataList);
		}
	
		this.mapData(this.dataCache, maskPattern);
	},

	setupPositionProbePattern : function(row, col)  {
		
		for (var r = -1; r <= 7; r++) {
			
			if (row + r <= -1 || this.moduleCount <= row + r) continue;
			
			for (var c = -1; c <= 7; c++) {
				
				if (col + c <= -1 || this.moduleCount <= col + c) continue;
				
				if ( (0 <= r && r <= 6 && (c === 0 || c === 6) ) || 
                     (0 <= c && c <= 6 && (r === 0 || r === 6) ) || 
                     (2 <= r && r <= 4 && 2 <= c && c <= 4) ) {
					this.modules[row + r][col + c] = true;
				} else {
					this.modules[row + r][col + c] = false;
				}
			}		
		}		
	},
	
	getBestMaskPattern : function() {
	
		var minLostPoint = 0;
		var pattern = 0;
	
		for (var i = 0; i < 8; i++) {
			
			this.makeImpl(true, i);
	
			var lostPoint = QRUtil.getLostPoint(this);
	
			if (i === 0 || minLostPoint >  lostPoint) {
				minLostPoint = lostPoint;
				pattern = i;
			}
		}
	
		return pattern;
	},
	
	createMovieClip : function(target_mc, instance_name, depth) {
	
		var qr_mc = target_mc.createEmptyMovieClip(instance_name, depth);
		var cs = 1;
	
		this.make();

		for (var row = 0; row < this.modules.length; row++) {
			
			var y = row * cs;
			
			for (var col = 0; col < this.modules[row].length; col++) {
	
				var x = col * cs;
				var dark = this.modules[row][col];
			
				if (dark) {
					qr_mc.beginFill(0, 100);
					qr_mc.moveTo(x, y);
					qr_mc.lineTo(x + cs, y);
					qr_mc.lineTo(x + cs, y + cs);
					qr_mc.lineTo(x, y + cs);
					qr_mc.endFill();
				}
			}
		}
		
		return qr_mc;
	},

	setupTimingPattern : function() {
		
		for (var r = 8; r < this.moduleCount - 8; r++) {
			if (this.modules[r][6] !== null) {
				continue;
			}
			this.modules[r][6] = (r % 2 === 0);
		}
	
		for (var c = 8; c < this.moduleCount - 8; c++) {
			if (this.modules[6][c] !== null) {
				continue;
			}
			this.modules[6][c] = (c % 2 === 0);
		}
	},
	
	setupPositionAdjustPattern : function() {
	
		var pos = QRUtil.getPatternPosition(this.typeNumber);
		
		for (var i = 0; i < pos.length; i++) {
		
			for (var j = 0; j < pos.length; j++) {
			
				var row = pos[i];
				var col = pos[j];
				
				if (this.modules[row][col] !== null) {
					continue;
				}
				
				for (var r = -2; r <= 2; r++) {
				
					for (var c = -2; c <= 2; c++) {
					
						if (Math.abs(r) === 2 || 
                            Math.abs(c) === 2 ||
                            (r === 0 && c === 0) ) {
							this.modules[row + r][col + c] = true;
						} else {
							this.modules[row + r][col + c] = false;
						}
					}
				}
			}
		}
	},
	
	setupTypeNumber : function(test) {
	
		var bits = QRUtil.getBCHTypeNumber(this.typeNumber);
        var mod;
	
		for (var i = 0; i < 18; i++) {
			mod = (!test && ( (bits >> i) & 1) === 1);
			this.modules[Math.floor(i / 3)][i % 3 + this.moduleCount - 8 - 3] = mod;
		}
	
		for (var x = 0; x < 18; x++) {
			mod = (!test && ( (bits >> x) & 1) === 1);
			this.modules[x % 3 + this.moduleCount - 8 - 3][Math.floor(x / 3)] = mod;
		}
	},
	
	setupTypeInfo : function(test, maskPattern) {
	
		var data = (this.errorCorrectLevel << 3) | maskPattern;
		var bits = QRUtil.getBCHTypeInfo(data);
        var mod;
	
		// vertical		
		for (var v = 0; v < 15; v++) {
	
			mod = (!test && ( (bits >> v) & 1) === 1);
	
			if (v < 6) {
				this.modules[v][8] = mod;
			} else if (v < 8) {
				this.modules[v + 1][8] = mod;
			} else {
				this.modules[this.moduleCount - 15 + v][8] = mod;
			}
		}
	
		// horizontal
		for (var h = 0; h < 15; h++) {
	
			mod = (!test && ( (bits >> h) & 1) === 1);
			
			if (h < 8) {
				this.modules[8][this.moduleCount - h - 1] = mod;
			} else if (h < 9) {
				this.modules[8][15 - h - 1 + 1] = mod;
			} else {
				this.modules[8][15 - h - 1] = mod;
			}
		}
	
		// fixed module
		this.modules[this.moduleCount - 8][8] = (!test);
	
	},
	
	mapData : function(data, maskPattern) {
		
		var inc = -1;
		var row = this.moduleCount - 1;
		var bitIndex = 7;
		var byteIndex = 0;
		
		for (var col = this.moduleCount - 1; col > 0; col -= 2) {
	
			if (col === 6) col--;
	
			while (true) {
	
				for (var c = 0; c < 2; c++) {
					
					if (this.modules[row][col - c] === null) {
						
						var dark = false;
	
						if (byteIndex < data.length) {
							dark = ( ( (data[byteIndex] >>> bitIndex) & 1) === 1);
						}
	
						var mask = QRUtil.getMask(maskPattern, row, col - c);
	
						if (mask) {
							dark = !dark;
						}
						
						this.modules[row][col - c] = dark;
						bitIndex--;
	
						if (bitIndex === -1) {
							byteIndex++;
							bitIndex = 7;
						}
					}
				}
								
				row += inc;
	
				if (row < 0 || this.moduleCount <= row) {
					row -= inc;
					inc = -inc;
					break;
				}
			}
		}
		
	}

};

QRCode.PAD0 = 0xEC;
QRCode.PAD1 = 0x11;

QRCode.createData = function(typeNumber, errorCorrectLevel, dataList) {
	
	var rsBlocks = QRRSBlock.getRSBlocks(typeNumber, errorCorrectLevel);
	
	var buffer = new QRBitBuffer();
	
	for (var i = 0; i < dataList.length; i++) {
		var data = dataList[i];
		buffer.put(data.mode, 4);
		buffer.put(data.getLength(), QRUtil.getLengthInBits(data.mode, typeNumber) );
		data.write(buffer);
	}

	// calc num max data.
	var totalDataCount = 0;
	for (var x = 0; x < rsBlocks.length; x++) {
		totalDataCount += rsBlocks[x].dataCount;
	}

	if (buffer.getLengthInBits() > totalDataCount * 8) {
		throw new Error("code length overflow. (" + 
            buffer.getLengthInBits() + 
            ">" +  
            totalDataCount * 8 + 
            ")");
	}

	// end code
	if (buffer.getLengthInBits() + 4 <= totalDataCount * 8) {
		buffer.put(0, 4);
	}

	// padding
	while (buffer.getLengthInBits() % 8 !== 0) {
		buffer.putBit(false);
	}

	// padding
	while (true) {
		
		if (buffer.getLengthInBits() >= totalDataCount * 8) {
			break;
		}
		buffer.put(QRCode.PAD0, 8);
		
		if (buffer.getLengthInBits() >= totalDataCount * 8) {
			break;
		}
		buffer.put(QRCode.PAD1, 8);
	}

	return QRCode.createBytes(buffer, rsBlocks);
};

QRCode.createBytes = function(buffer, rsBlocks) {

	var offset = 0;
	
	var maxDcCount = 0;
	var maxEcCount = 0;
	
	var dcdata = new Array(rsBlocks.length);
	var ecdata = new Array(rsBlocks.length);
	
	for (var r = 0; r < rsBlocks.length; r++) {

		var dcCount = rsBlocks[r].dataCount;
		var ecCount = rsBlocks[r].totalCount - dcCount;

		maxDcCount = Math.max(maxDcCount, dcCount);
		maxEcCount = Math.max(maxEcCount, ecCount);
		
		dcdata[r] = new Array(dcCount);
		
		for (var i = 0; i < dcdata[r].length; i++) {
			dcdata[r][i] = 0xff & buffer.buffer[i + offset];
		}
		offset += dcCount;
		
		var rsPoly = QRUtil.getErrorCorrectPolynomial(ecCount);
		var rawPoly = new QRPolynomial(dcdata[r], rsPoly.getLength() - 1);

		var modPoly = rawPoly.mod(rsPoly);
		ecdata[r] = new Array(rsPoly.getLength() - 1);
		for (var x = 0; x < ecdata[r].length; x++) {
            var modIndex = x + modPoly.getLength() - ecdata[r].length;
			ecdata[r][x] = (modIndex >= 0)? modPoly.get(modIndex) : 0;
		}

	}
	
	var totalCodeCount = 0;
	for (var y = 0; y < rsBlocks.length; y++) {
		totalCodeCount += rsBlocks[y].totalCount;
	}

	var data = new Array(totalCodeCount);
	var index = 0;

	for (var z = 0; z < maxDcCount; z++) {
		for (var s = 0; s < rsBlocks.length; s++) {
			if (z < dcdata[s].length) {
				data[index++] = dcdata[s][z];
			}
		}
	}

	for (var xx = 0; xx < maxEcCount; xx++) {
		for (var t = 0; t < rsBlocks.length; t++) {
			if (xx < ecdata[t].length) {
				data[index++] = ecdata[t][xx];
			}
		}
	}

	return data;

};

module.exports = QRCode;

},
}; const cache={};function require(s){const name=s.replace(/^\.\//, "");if(cache[name])return cache[name].exports;const module={exports:{}};if(!factories[name])throw Error("Unknown QR module: "+name);cache[name]=module;factories[name](require,module,module.exports);return module.exports;}const QRCode=require("index");const ec=require("QRErrorCorrectLevel");return function(input){const qr=new QRCode(-1,ec.M);qr.addData(input);qr.make();return qr;};})();

  // The QR payload is encoded entirely inside the browser; no passport details
  // are sent to an external QR-image API or to the Biz-Flights server.
  // NOTE: This QR contains personal data in plain text. Anyone with the PDF
  // can scan it. Only create/send PDFs to the intended traveler or agent.
  function qrPayload(d,flightDesignator){
    const lines=['EXPLORE FLIGHTS - ITINERARY DETAILS'];
    lines.push(`Reference: ${sanitize(d.bookingReference||'')}`);
    lines.push('Passengers:');
    d.travelers.forEach((p,i)=>{
      const title=p.gender==='Male'?'Mr ':p.gender==='Female'?'Ms ':'';
      const full=sanitize((title+(p.name||'')).trim());
      const passport=sanitize(p.passport||'NOT PROVIDED');
      const dob=p.dob?fmtDate(p.dob):'NOT PROVIDED';
      lines.push(`${i+1}. ${full} - ${passport} - ${dob}`);
    });
    const outbound=d.segments.filter(s=>String(s.routeIndex||1)==='1');
    const returning=d.segments.filter(s=>String(s.routeIndex||1)==='2');
    lines.push(`Flight: ${outbound.map(flightDesignator).join(' / ')||'N/A'}`);
    if(returning.length)lines.push(`Return: ${returning.map(flightDesignator).join(' / ')}`);
    if(outbound.length)lines.push(`Flight Date: ${fmtDate(outbound[0].isoDate||outbound[0].date)}`);
    if(returning.length)lines.push(`Return Date: ${fmtDate(returning[0].isoDate||returning[0].date)}`);
    lines.push('Reservation status: NOT CONFIRMED');
    return lines.join('\n');
  }
  function drawQr(pdf,text,x,y,dim){
    const qr=ExploreQR(text),n=qr.getModuleCount(),quiet=4;
    const cell=dim/(n+quiet*2);
    pdf.rect(x,y,dim,dim,white);
    for(let row=0;row<n;row++){
      let col=0;
      while(col<n){
        if(!qr.isDark(row,col)){col++;continue;}
        const start=col;while(col<n&&qr.isDark(row,col))col++;
        pdf.rect(x+(quiet+start)*cell,y+(quiet+row)*cell,(col-start)*cell,cell,'#000000');
      }
    }
  }
  async function generate(d){
    if(!d?.segments?.length||!d?.travelers?.length)throw Error('Complete your flight selection and passenger details first.');
    const pdf=new PDF();let y=0,page=1;
    const iataCode=String(d.carrierCode||'').toUpperCase().replace(/[^A-Z0-9]/g,'').slice(0,3);
    const logoCodes=[...new Set([iataCode,...d.segments.map(s=>String(s.operatingCode||s.marketingCode||iataCode).toUpperCase().replace(/[^A-Z0-9]/g,'').slice(0,3))])]
      .filter(code=>/^[A-Z0-9]{2,3}$/.test(code));
    // Prefer genuine logo files shipped in this ZIP. The Node API is an optional
    // fallback for logos not yet present locally. Missing logos must never block
    // the itinerary PDF: show a text airline code instead, not a fake emblem.
    const logoPairs=await Promise.all(logoCodes.map(async code=>{
      let image=null;
      for(const ext of ['jpg','png','jpeg']){
        image=await loadJpeg(`${window.AIR_TICKET.assets}airlines/${encodeURIComponent(code)}.${ext}`);
        if(image)break;
      }
      if(!image)image=await loadJpeg(`${window.AIR_TICKET.api}airline-logo&code=${encodeURIComponent(code)}`);
      return [code,image];
    }));
    const logos=new Map(logoPairs);
    const logo=logos.get(iataCode);
    // Display the complete flight designator (airline IATA + numeric flight number)
    // even when a supplier sends only the numeric portion of the flight number.
    const flightDesignator=seg=>{
      const raw=sanitize(seg?.flightNo||'').trim();
      const prefix=sanitize(seg?.operatingCode||seg?.marketingCode||iataCode).toUpperCase().replace(/[^A-Z0-9]/g,'').slice(0,3);
      if(/^\d{1,4}$/.test(raw))return `${prefix} ${raw}`;
      return raw||'-';
    };
    // An airline OFFER is not proof of a reservation. This UI has no booking-create endpoint.
    const booked=false; // Set only by a future verified, server-side booking integration.
    // Six-character internal itinerary reference, deterministic for an unchanged booking reference.
    // This is NEVER an airline-issued PNR.
    const internalCode=(()=>{let v=2166136261;for(const ch of String(d.bookingReference||'BF-ITINERARY')){v^=ch.charCodeAt(0);v=Math.imul(v,16777619)>>>0;}return v.toString(36).toUpperCase().padStart(6,'0').slice(-6);})();
    const issuedDate=fmtDate(d.createdAt||d.issuedAt||d.generated||new Date().toISOString());
    const code=(d.carrierCode||'EF').toUpperCase().slice(0,3),airline=sanitize(d.carrier||'Biz-Flights');
    const ink=palette[code]||navy,ref=sanitize(d.bookingReference||'BF-ITINERARY');
    const currency=d.liveOffer&&d.currency?d.currency:'BDT';
    const total=d.liveOffer?Number(d.fareTotal||0):Number(d.fareSample||0)*d.travelers.length;
    const pending='NOT CONFIRMED';
    function top(continuation=false){
      if(logo)pdf.fittedImage(logo,L+8,13,147,37);
      else {pdf.txt(airline || iataCode || 'AIRLINE',L+9,35,14,navy,true);}

      pdf.txt('FLIGHT ITINERARY',L+16,65,7.3,navy,true);
      pdf.right(`Booking Ref: ${ref}`,R,23,8.5,dark,true);
      pdf.right(`Biz-Flights Ref: ${internalCode}`,R,35,8.0,dark,true);
      pdf.right(`Status: ${pending}`,R,48,8.0,dark,true);
      pdf.right(`Issued: ${issuedDate}`,R,61,7.6,dark,true);
      y=continuation?82:81;
    }
    function foot(){pdf.line(L,799,R,799,gray);pdf.txt(`Biz-Flights  |  Itinerary ref ${ref}  |  No airline booking issued`,L+2,813,7,muted);pdf.right(`PAGE ${page} / {TOTAL}`,R,813,7,muted)}
    function newPage(){foot();pdf.newPage();page++;top(true)}
    function room(h){if(y+h>792)newPage()}
    function bar(title){room(24);pdf.rect(L,y,R-L,18,navy);pdf.txt(title.toUpperCase(),L+8,y+12,9.1,white,true);y+=18}
    top();
    bar('Passenger information');
    // The reference table uses six columns and light zebra rows.
    const cols=[L+8,L+29,L+190,L+279,L+368,L+467];
    pdf.rect(L,y,R-L,18,light);['SL','FULL NAME','DATE OF BIRTH','PASSPORT NO.','PASSPORT EXPIRY','NATIONALITY','PAX TYPE'].forEach((v,i)=>{
      const x=[L+6,L+30,L+186,L+262,L+356,L+461,R-53][i];
      const size=[7.1,7.1,7.1,7.1,6.7,5.8,5.8][i];
      pdf.txt(v,x,y+12,size,dark,true)
    });y+=18;
    d.travelers.forEach((p,i)=>{
      room(26);const row=26;pdf.rect(L,y,R-L,row,i%2?light:white);pdf.line(L,y+row,R,y+row,gray);
      pdf.txt(i+1,L+8,y+17,8,muted);
      const nm=sanitize(p.name||'PASSENGER');const multiline=width(nm,8)>149;
      if(multiline){const parts=nm.split(' ');let a='',b='';for(const part of parts){if(!b&&width(a+' '+part,8)<147)a+=(a?' ':'')+part;else b+=(b?' ':'')+part}pdf.txt(a,L+31,y+11,fit(a,8,149),dark,true);pdf.txt(b,L+31,y+20,fit(b,7.5,149),dark,true)}else pdf.txt(nm,L+31,y+17,fit(nm,8.3,149),dark,true);
      pdf.txt(p.dob?fmtDate(p.dob):'-',L+186,y+17,7.8,dark);
      pdf.txt(sanitize(p.passport||'-'),L+263,y+17,fit(p.passport||'-',8,84),dark,true);
      pdf.txt(p.passportExpiry?fmtDate(p.passportExpiry):'-',L+356,y+17,7.7,dark);
      pdf.txt(sanitize(p.nationality||'-'),L+460,y+17,fit(p.nationality||'-',7.5,79),dark);
      pdf.txt(sanitize(p.type||'Adult'),R-59,y+17,7.8,dark);
      y+=row;
    });
    y+=9;bar('Flight itinerary');
    function flightTableHead(){pdf.rect(L,y,R-L,18,light);const cx=[L+8,L+88,L+175,L+287,L+370,L+412,L+471,L+530];const th=['FLIGHT','DATE','FROM','TO','DEP.','ARR.','DURATION','CLASS'];th.forEach((t,i)=>pdf.txt(t,cx[i],y+12,fit(t,7.2,[72,80,105,76,42,42,54,39][i]),dark,true));pdf.line(L,y+18,R,y+18,navy,1);y+=18}
    flightTableHead();
    const groups=new Map();d.segments.forEach((s,i)=>{const k=String(s.routeIndex||1);if(!groups.has(k))groups.set(k,[]);groups.get(k).push(s)});
    const direction=k=>{const n=Number(k);return d.trip==='Round trip'?(n===1?'OUTBOUND':'RETURN'):(d.trip==='Multi-city'?`JOURNEY ${n}`:'OUTBOUND')};
    let ix=0;
    for(const [k,ss] of groups){room(18);pdf.txt(direction(k),L+8,y+12,7.5,orange,true);pdf.txt(`Baggage: ${sanitize(ss[0]?.baggage||'Refer to airline rules')}`,L+87,y+12,7.3,muted);y+=17;
      for(let j=0;j<ss.length;j++){
        const s=ss[j];room(29);pdf.line(L,y+28,R,y+28,gray);if(ix++%2)pdf.rect(L,y,R-L,28,'#FAFBFD');
        const sectorLogo=logos.get(String(s.operatingCode||s.marketingCode||iataCode).toUpperCase())||logo;
        if(sectorLogo)pdf.fittedImage(sectorLogo,L+7,y+2,51,10);
        else pdf.txt(sanitize(s.operatingCode||s.marketingCode||iataCode||'AIRLINE'),L+8,y+11,7.2,navy,true);
        pdf.txt(flightDesignator(s),L+7,y+20,fit(flightDesignator(s),7.5,70),dark,true);
        pdf.txt(sanitize(s.operatingAirline||airline),L+7,y+27,5.4,muted);
        pdf.txt(sanitize(s.date||'-'),L+86,y+17,fit(s.date||'-',8,86),dark);
        const frm=`${sanitize(s.from?.city||'')} (${sanitize(s.from?.code||'')})`;
        const to=`${sanitize(s.to?.city||'')} (${sanitize(s.to?.code||'')})`;
        pdf.txt(frm,L+174,y+17,fit(frm,8.6,109),dark,true);
        pdf.txt(to,L+285,y+17,fit(to,8.6,79),dark,true);
        pdf.txt(sanitize(s.departureTime||'-'),L+369,y+17,8.5,dark,true);
        pdf.txt(sanitize(s.arrivalTime||'-'),L+411,y+17,8.5,dark,true);
        pdf.txt(sanitize(s.duration||'-'),L+469,y+17,fit(s.duration||'-',8.1,53),dark);
        pdf.txt(sanitize(s.cabin||d.cabin||'Economy'),L+529,y+17,fit(s.cabin||d.cabin||'Economy',7.7,38),dark);
        y+=28;
        if(j<ss.length-1){room(16);pdf.rect(L,y,R-L,15,cream);const place=s.to?.city||'Connecting airport';pdf.txt(`    Connection at ${sanitize(place)} (${sanitize(s.to?.code||'')}) - change planes`,L+185,y+10.5,7.3,orange);y+=15;}
      }
    }
    y+=5;room(32);
    pdf.line(L,y,R,y,gray);y+=13;
    const outbound=d.segments.filter(x=>String(x.routeIndex||1)==='1').map(flightDesignator).join(' - ');
    const ret=d.segments.filter(x=>String(x.routeIndex||1)==='2').map(flightDesignator).join(' - ');
    pdf.txt(`Flights: Outbound ${sanitize(outbound)}${ret?'  |  Return '+sanitize(ret):''}`,L+7,y,fit(`Flights: Outbound ${outbound}${ret?' | Return '+ret:''}`,8.2,558),dark);
    y+=13;pdf.txt(`All times local to each airport. Schedules and aircraft are subject to airline changes.`,L+7,y,7.5,muted);
    y+=14;bar('Fare summary');
    pdf.rect(L,y,R-L,18,light);pdf.txt('DESCRIPTION',L+8,y+12,7.5,dark,true);pdf.right(`AMOUNT (${currency})`,R-9,y+12,7.5,dark,true);y+=18;
    const amount=fmtMoney(total,currency).replace(`${currency} `,'');
    const fareRows=[
      ['Airfare (estimated / offered)',amount],
      ['Taxes, fees and surcharges','Included where quoted'],
      ['Convenience fee','Not charged']
    ];
    fareRows.forEach(([label,value],i)=>{room(19);pdf.rect(L,y,R-L,19,i%2?white:light);pdf.line(L,y+19,R,y+19,gray);pdf.txt(label,L+8,y+13,8.1,dark);pdf.right(value,R-9,y+13,7.8,dark);y+=19});
    room(24);pdf.rect(L,y,R-L,24,bluePale);pdf.txt(d.liveOffer?'Total offered fare (not charged)':'Estimated total (not charged)',L+8,y+16,9.4,dark,true);pdf.right(fmtMoney(total,currency),R-9,y+16,10.2,dark,true);y+=32;
    bar('Important information');
    room(110);
    const notes=[
      'Itinerary information is not proof of an airline reservation.',
      'No airline PNR or e-ticket has been issued.',
      'Check departure/arrival times with the operating airline.',
      'Arrive at least 3 hours before international departures.',
      'Passport and visa requirements vary by destination.',
      'Baggage rules are determined by the operating airline.',
      'Price and availability may change prior to booking.',
      'For actual reservations, obtain a confirmed airline record.'
    ];
    let leftY=y+11,rightY=y+11;
    notes.slice(0,4).forEach(v=>{pdf.txt('*',L+10,leftY,8,navy);leftY+=pdf.wrapped(v,L+20,leftY,225,7.4,10,dark,3)+4;});
    notes.slice(4,8).forEach(v=>{pdf.txt('*',L+245,rightY,8,navy);rightY+=pdf.wrapped(v,L+255,rightY,205,7.4,10,dark,3)+4;});
    // Public website URL only; this code does not validate an airline reservation.
    const qrText=qrPayload(d,flightDesignator);
    const qrX=R-93,qrY=y+8,dim=78;
    pdf.rect(qrX-5,qrY-5,dim+10,dim+10,white,gray);
    drawQr(pdf,qrText,qrX,qrY,dim);
    pdf.txt('SCAN FOR TRAVELER DETAILS',qrX-13,qrY+dim+18,6.2,muted,true);
    y=Math.max(leftY,rightY,qrY+dim+23)+10;
    if(y>792){ // for long documents, the important-information block may end on a new page.
      newPage();
    }
    foot();
    for(const c of pdf.pages)for(let i=0;i<c.length;i++)c[i]=c[i].replace(/\{TOTAL\}/g,String(pdf.pages.length));
    return pdf.file();
  }
  async function download(data){const blob=await generate(data);const filename=`Biz_Flights_Itinerary_${sanitize(data.bookingReference||'')||'Itinerary'}.pdf`.replace(/[^\w.\-]/g,'_');const url=URL.createObjectURL(blob),a=document.createElement('a');a.href=url;a.download=filename;a.style.display='none';document.body.appendChild(a);a.click();a.remove();setTimeout(()=>URL.revokeObjectURL(url),5000);return filename}
  window.ExploreFlightsPDF={generate,download};
})();
