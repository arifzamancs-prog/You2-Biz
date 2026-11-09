// Dummy Air Ticket passport scanner tests. Run: node --test tests/air_ticket_passport_ocr_test.js
// Part 1 is biz-flight's original test suite; part 2 covers filler '<' misread as letters.
'use strict';
const test=require('node:test');
const assert=require('node:assert/strict');
const mrz=require('../air_ticket/app/passport-ocr.js');
const pad=(v,n)=>String(v).padEnd(n,'<').slice(0,n);
function makePassport({surname='RAHMAN',given='NADIA<AFRIN',passport='A01234567',dob='000721',expiry='310815',nationality='BGD'}={}){
  const l1=pad(`P<BGD${surname}<<${given}`,44);
  const docNo=pad(passport,9),optional=pad('',14);
  const base=docNo+mrz.checkDigit(docNo)+nationality+dob+mrz.checkDigit(dob)+'F'+expiry+mrz.checkDigit(expiry)+optional+mrz.checkDigit(optional);
  const final=base+mrz.checkDigit(docNo+mrz.checkDigit(docNo)+dob+mrz.checkDigit(dob)+expiry+mrz.checkDigit(expiry)+optional+mrz.checkDigit(optional));
  assert.equal(final.length,44);
  return [l1,final];
}
const referenceDate=new Date('2026-11-04T00:00:00Z');
test('valid TD3 MRZ fills only the expected structured details',()=>{
  const [a,b]=makePassport();const x=mrz.parse(`${a}\n${b}`,referenceDate);
  assert.equal(x.first,'NADIA AFRIN');assert.equal(x.last,'RAHMAN');
  assert.equal(x.nationality,'Bangladeshi');assert.equal(x.gender,'Female');
  assert.equal(x.passport,'A01234567');assert.equal(x.dob,'2000-07-21');
  assert.equal(x.passportExpiry,'2031-08-15');assert.equal(x.validated,3);
  assert.equal(x.compositeValid,true);
});
test('unrelated text above/below MRZ must never be used',()=>{
  const [a,b]=makePassport();const noisy=`PLACE OF BIRTH: WRONG PLACE\nDATE 999999\nNOISE 192003939\n${a}\n${b}\nEXTRA OCR TEXT AAAAA`;
  assert.equal(mrz.parse(noisy,referenceDate).first,'NADIA AFRIN');
  assert.equal(mrz.parse(noisy,referenceDate).passport,'A01234567');
});
test('two MRZ lines joined by OCR are supported',()=>{
  const [a,b]=makePassport();assert.equal(mrz.parse(a+b,referenceDate).passport,'A01234567');
});
test('invalid passport check digit must not auto-fill a number',()=>{
  const [a,b]=makePassport();const bad=b.slice(0,9)+(b[9]==='0'?'1':'0')+b.slice(10);
  const r=mrz.parse(`${a}\n${bad}`,referenceDate);
  assert.equal(r.passport,'');assert.equal(r.dob,'2000-07-21');assert.equal(r.passportExpiry,'2031-08-15');
});
test('malformed MRZ/truncated/unrelated strings are rejected',()=>{
  const [a,b]=makePassport();
  for(const raw of ['ABC DEF\n12345678901234567890',`${a}\n${b.slice(0,29)}`])assert.throws(()=>mrz.parse(raw,referenceDate));
});
test('invalid calendar date does not populate DOB even with checksum matching',()=>{
  const [a,b]=makePassport({dob:'000231'});const r=mrz.parse(`${a}\n${b}`,referenceDate);
  assert.equal(r.dob,'');
});
test('OCR 0/O confusion in date positions can be normalized only after MRZ validation',()=>{
  const [a,b]=makePassport({dob:'000721'});const corrupted=b.slice(0,13)+'OOO721'+b.slice(19);
  const r=mrz.parse(`${a}\n${corrupted}`,referenceDate);assert.equal(r.dob,'2000-07-21');
});

// ---- Part 2: MRZ filler '<' misread as letters (e.g. NURJAHAN -> NURJAHANK) ----
const realLine2='A022096890BGD5102024F26110881451546699<<<<22';

test('long filler misread as C/L/K after a single name is treated as filler',()=>{
  const l1='P<BGDNURJAHAN<CLLLLLLLLLLLLKLLLLLKLLLLLKLLLKLLLLKLLKL';
  const r=mrz.parse(l1+'\n'+realLine2);
  assert.equal(r.last,'NURJAHAN');
  assert.equal(r.first,'');
  assert.equal(r.passport,'A02209689');
  assert.equal(r.dob,'1951-02-02');
});

test('normalizeNameFiller keeps real short given names',()=>{
  const l1=pad('P<GBRSMITH<<LILI',44);
  assert.equal(mrz.normalizeNameFiller(l1),l1);
});

test('printed name removes a misread trailing K',()=>{
  const r=mrz.confirmNamesWithVisual({first:'',last:'NURJAHANK'},'SURNAME\nNURJAHAN\nGIVEN NAME\nBANGLADESHI');
  assert.equal(r.last,'NURJAHAN');
  assert.equal(r.nameNeedsReview,false);
});

test('a real name ending in K is kept when printed that way',()=>{
  const r=mrz.confirmNamesWithVisual({first:'ABDUL',last:'MALIK'},'SURNAME MALIK GIVEN NAME ABDUL');
  assert.equal(r.last,'MALIK');
  assert.equal(r.first,'ABDUL');
});

test('unclear printed text keeps the MRZ name and asks for review',()=>{
  const r=mrz.confirmNamesWithVisual({first:'',last:'NURJAHANK'},'PASSPORT BGD');
  assert.equal(r.last,'NURJAHANK');
  assert.equal(r.nameNeedsReview,true);
});

test('names not ending in a look-alike letter skip the printed check',()=>{
  assert.equal(mrz.nameNeedsVisualCheck({first:'NADIA',last:'RAHMAN'}),false);
  assert.equal(mrz.nameNeedsVisualCheck({first:'',last:'NURJAHANK'}),true);
});
// ---- Part 3: scanner robustness ----
test('up to three misread filler letters are removed when the printed name is shorter',()=>{
  assert.equal(mrz.confirmNamesWithVisual({first:'',last:'NURJAHANKCL'},'Surname NURJAHAN').last,'NURJAHAN');
  assert.equal(mrz.confirmNamesWithVisual({first:'',last:'NURJAHANSC'},'Surname NURJAHAN').last,'NURJAHAN');
});

test('real names ending in S are kept when printed in full',()=>{
  const r=mrz.confirmNamesWithVisual({first:'JOHN',last:'THOMAS'},'Surname THOMAS Given Names JOHN');
  assert.equal(r.last,'THOMAS');
  assert.equal(r.nameNeedsReview,false);
});

test('one OCR swap in the passport number is repaired (D read for 0)',()=>{
  const l1='P<BGDNURJAHAN<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<';
  const r=mrz.parse(l1+'\n'+'AD22096890BGD5102024F26110881451546699<<<<22');
  assert.equal(r.passport,'A02209689');
  assert.equal(r.validated,3);
});

test('a passport number needing two swaps is not invented',()=>{
  const l1='P<BGDNURJAHAN<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<';
  const r=mrz.parse(l1+'\n'+'ADZ2096890BGD5102024F26110881451546699<<<<22');
  assert.equal(r.passport,'');
  assert.equal(r.checks.passport,false);
});

test('line 1 is found when OCR reads "P<" as "PK"',()=>{
  const r=mrz.parse('PKBGDNURJAHAN<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<\nA022096890BGD5102024F26110881451546699<<<<22');
  assert.equal(r.last,'NURJAHAN');
  assert.equal(r.passport,'A02209689');
});

test('line 2 is aligned when OCR adds junk before it',()=>{
  const r=mrz.parse('P<BGDNURJAHAN<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<\nXX1A022096890BGD5102024F26110881451546699<<<<22');
  assert.equal(r.passport,'A02209689');
  assert.equal(r.dob,'1951-02-02');
});