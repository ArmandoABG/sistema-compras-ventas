/*
 * Sistema Integral - lector QR fotográfico local.
 * Diseñado para los QR SIQR generados por inc/qr_svg.php (QR v5-M, 37x37).
 * No usa Internet, cámara en vivo ni servicios externos.
 */
(function (global) {
    'use strict';

    const N = 37;
    const DATA_PER_BLOCK = 43;
    const ECC_PER_BLOCK = 24;
    const TOTAL_CODEWORDS = 134;
    const GF_EXP = new Uint8Array(512);
    const GF_LOG = new Uint8Array(256);

    (function initGF() {
        let x = 1;
        for (let i = 0; i < 255; i++) {
            GF_EXP[i] = x;
            GF_LOG[x] = i;
            x <<= 1;
            if (x & 0x100) x ^= 0x11D;
        }
        for (let i = 255; i < 512; i++) GF_EXP[i] = GF_EXP[i - 255];
    })();

    function gfMul(a, b) {
        if (!a || !b) return 0;
        return GF_EXP[GF_LOG[a] + GF_LOG[b]];
    }
    function gfDiv(a, b) {
        if (!a) return 0;
        if (!b) throw new Error('División GF entre cero');
        let e = GF_LOG[a] - GF_LOG[b];
        if (e < 0) e += 255;
        return GF_EXP[e];
    }
    function gfInv(a) {
        if (!a) throw new Error('No existe inverso GF de cero');
        return GF_EXP[255 - GF_LOG[a]];
    }
    function gfPow(exp) {
        exp %= 255;
        if (exp < 0) exp += 255;
        return GF_EXP[exp];
    }
    function polyEvalDesc(msg, x) {
        let y = msg[0] || 0;
        for (let i = 1; i < msg.length; i++) y = gfMul(y, x) ^ msg[i];
        return y;
    }
    function polyEvalAsc(coeff, x) {
        let y = 0, p = 1;
        for (let i = 0; i < coeff.length; i++) {
            y ^= gfMul(coeff[i], p);
            p = gfMul(p, x);
        }
        return y;
    }
    function syndromes(msg, nsym) {
        const s = new Uint8Array(nsym);
        for (let i = 0; i < nsym; i++) s[i] = polyEvalDesc(msg, gfPow(i));
        return s;
    }
    function rsLocatorBM(s) {
        const nsym = s.length;
        const C = new Uint8Array(nsym + 1);
        const B = new Uint8Array(nsym + 1);
        C[0] = 1; B[0] = 1;
        let L = 0, m = 1, b = 1;
        for (let n = 0; n < nsym; n++) {
            let d = s[n];
            for (let i = 1; i <= L; i++) d ^= gfMul(C[i], s[n - i]);
            if (d === 0) {
                m++;
                continue;
            }
            const T = C.slice();
            const coef = gfDiv(d, b);
            for (let i = 0; i + m < C.length; i++) {
                if (B[i]) C[i + m] ^= gfMul(coef, B[i]);
            }
            if (2 * L <= n) {
                L = n + 1 - L;
                B.set(T);
                b = d;
                m = 1;
            } else {
                m++;
            }
        }
        return { coeff: C.slice(0, L + 1), degree: L };
    }
    function rsFindPositions(locator, length) {
        const pos = [];
        for (let p = 0; p < length; p++) {
            const l = length - 1 - p;
            if (polyEvalAsc(locator, gfPow(-l)) === 0) pos.push(p);
        }
        return pos;
    }
    function gfSolve(A, b) {
        const n = b.length;
        const M = Array.from({length:n}, (_, r) => {
            const row = new Uint8Array(n + 1);
            for (let c = 0; c < n; c++) row[c] = A[r][c];
            row[n] = b[r];
            return row;
        });
        for (let col = 0; col < n; col++) {
            let pivot = col;
            while (pivot < n && M[pivot][col] === 0) pivot++;
            if (pivot === n) return null;
            if (pivot !== col) { const t = M[col]; M[col] = M[pivot]; M[pivot] = t; }
            const inv = gfInv(M[col][col]);
            for (let c = col; c <= n; c++) M[col][c] = gfMul(M[col][c], inv);
            for (let r = 0; r < n; r++) {
                if (r === col || M[r][col] === 0) continue;
                const f = M[r][col];
                for (let c = col; c <= n; c++) M[r][c] ^= gfMul(f, M[col][c]);
            }
        }
        return Uint8Array.from(M.map(row => row[n]));
    }
    function rsCorrect(input, nsym) {
        const msg = Uint8Array.from(input);
        const s = syndromes(msg, nsym);
        let has = false;
        for (const v of s) if (v) { has = true; break; }
        if (!has) return msg;
        const loc = rsLocatorBM(s);
        if (loc.degree <= 0 || loc.degree > Math.floor(nsym / 2)) return null;
        const positions = rsFindPositions(loc.coeff, msg.length);
        if (positions.length !== loc.degree) return null;
        const e = positions.length;
        const A = Array.from({length:e}, () => new Uint8Array(e));
        for (let k = 0; k < e; k++) {
            for (let j = 0; j < e; j++) {
                const X = gfPow(msg.length - 1 - positions[j]);
                A[k][j] = k === 0 ? 1 : gfPow(GF_LOG[X] * k);
            }
        }
        const mags = gfSolve(A, s.slice(0, e));
        if (!mags) return null;
        for (let i = 0; i < e; i++) msg[positions[i]] ^= mags[i];
        const after = syndromes(msg, nsym);
        for (const v of after) if (v) return null;
        return msg;
    }

    function maskBit(mask, r, c) {
        switch (mask) {
            case 0: return ((r + c) % 2) === 0;
            case 1: return (r % 2) === 0;
            case 2: return (c % 3) === 0;
            case 3: return ((r + c) % 3) === 0;
            case 4: return ((Math.floor(r / 2) + Math.floor(c / 3)) % 2) === 0;
            case 5: return (((r * c) % 2) + ((r * c) % 3)) === 0;
            case 6: return ((((r * c) % 2) + ((r * c) % 3)) % 2) === 0;
            case 7: return ((((r + c) % 2) + ((r * c) % 3)) % 2) === 0;
            default: return false;
        }
    }

    function functionMap() {
        const f = Array.from({length:N}, () => new Uint8Array(N));
        const set = (r,c) => { if (r >= 0 && r < N && c >= 0 && c < N) f[r][c] = 1; };
        [[3,3],[3,N-4],[N-4,3]].forEach(([cr,cc]) => {
            for (let dr=-4; dr<=4; dr++) for (let dc=-4; dc<=4; dc++) set(cr+dr,cc+dc);
        });
        for (let i=8; i<N-8; i++) { set(6,i); set(i,6); }
        for (let dr=-2; dr<=2; dr++) for (let dc=-2; dc<=2; dc++) set(30+dr,30+dc);
        for (let i=0; i<=5; i++) set(i,8);
        set(7,8); set(8,8); set(8,7);
        for (let i=9; i<15; i++) set(8,14-i);
        for (let i=0; i<8; i++) set(8,N-1-i);
        for (let i=8; i<15; i++) set(N-15+i,8);
        set(29,8);
        return f;
    }
    const FUNC = functionMap();

    function codewordsForMask(matrix, mask) {
        const bits = [];
        let up = true;
        for (let right = N - 1; right >= 1; right -= 2) {
            if (right === 6) right--;
            for (let v = 0; v < N; v++) {
                const r = up ? N - 1 - v : v;
                for (let j = 0; j < 2; j++) {
                    const c = right - j;
                    if (FUNC[r][c]) continue;
                    let bit = !!matrix[r][c];
                    if (maskBit(mask, r, c)) bit = !bit;
                    bits.push(bit ? 1 : 0);
                }
            }
            up = !up;
        }
        const out = new Uint8Array(TOTAL_CODEWORDS);
        for (let i=0; i<TOTAL_CODEWORDS; i++) {
            let b = 0;
            for (let j=0; j<8; j++) b = (b << 1) | (bits[i*8+j] || 0);
            out[i] = b;
        }
        return out;
    }

    function correctedData(codewords) {
        const b0 = new Uint8Array(DATA_PER_BLOCK + ECC_PER_BLOCK);
        const b1 = new Uint8Array(DATA_PER_BLOCK + ECC_PER_BLOCK);
        for (let i=0; i<DATA_PER_BLOCK; i++) { b0[i] = codewords[i*2]; b1[i] = codewords[i*2+1]; }
        const off = DATA_PER_BLOCK * 2;
        for (let i=0; i<ECC_PER_BLOCK; i++) { b0[DATA_PER_BLOCK+i] = codewords[off+i*2]; b1[DATA_PER_BLOCK+i] = codewords[off+i*2+1]; }
        const c0 = rsCorrect(b0, ECC_PER_BLOCK);
        const c1 = rsCorrect(b1, ECC_PER_BLOCK);
        if (!c0 || !c1) return null;
        const data = new Uint8Array(DATA_PER_BLOCK * 2);
        data.set(c0.slice(0,DATA_PER_BLOCK),0);
        data.set(c1.slice(0,DATA_PER_BLOCK),DATA_PER_BLOCK);
        return data;
    }

    function bitsFromBytes(bytes) {
        const bits = [];
        for (const b of bytes) for (let i=7;i>=0;i--) bits.push((b >> i) & 1);
        return bits;
    }
    function readBits(bits, state, count) {
        if (state.i + count > bits.length) return null;
        let v = 0;
        for (let j=0;j<count;j++) v = (v << 1) | bits[state.i++];
        return v;
    }
    function parsePayload(data) {
        const bits = bitsFromBytes(data);
        const st = {i:0};
        const mode = readBits(bits,st,4);
        if (mode !== 4) return null;
        const len = readBits(bits,st,8);
        if (len === null || len < 5 || len > 84) return null;
        let text = '';
        for (let i=0;i<len;i++) {
            const b=readBits(bits,st,8); if (b===null) return null;
            text += String.fromCharCode(b);
        }
        return /^SIQR:[0-9a-f]{64}$/i.test(text) ? text : null;
    }
    function decodeMatrix(matrix) {
        for (let mask=0; mask<8; mask++) {
            const cw = codewordsForMask(matrix,mask);
            const data = correctedData(cw);
            if (!data) continue;
            const payload = parsePayload(data);
            if (payload) return payload;
        }
        return null;
    }

    function otsu(gray) {
        const hist = new Uint32Array(256);
        for (let i=0;i<gray.length;i++) hist[gray[i]]++;
        let total=gray.length,sum=0;
        for(let i=0;i<256;i++) sum += i*hist[i];
        let sumB=0,wB=0,best=0,max=-1;
        for(let t=0;t<256;t++) {
            wB += hist[t]; if (!wB) continue;
            const wF=total-wB; if(!wF) break;
            sumB += t*hist[t];
            const mB=sumB/wB,mF=(sum-sumB)/wF;
            const between=wB*wF*(mB-mF)*(mB-mF);
            if(between>max){max=between;best=t;}
        }
        return best;
    }
    function binaryFromGray(gray, threshold) {
        const out=new Uint8Array(gray.length);
        for(let i=0;i<gray.length;i++) out[i]=gray[i] < threshold ? 1 : 0;
        return out;
    }
    function pattern5(counts) {
        const total=counts[0]+counts[1]+counts[2]+counts[3]+counts[4];
        if(total < 7) return false;
        const unit=total/7, tol=unit*0.75;
        return Math.abs(counts[0]-unit)<tol && Math.abs(counts[1]-unit)<tol && Math.abs(counts[2]-3*unit)<3*tol && Math.abs(counts[3]-unit)<tol && Math.abs(counts[4]-unit)<tol;
    }
    function centerFromEnd(c,end){return end-c[4]-c[3]-c[2]/2;}

    function crossVertical(bin,w,h,startY,cx,maxCount,origTotal) {
        const x=Math.round(cx); if(x<0||x>=w) return null;
        const c=[0,0,0,0,0]; let y=Math.round(startY);
        while(y>=0 && bin[y*w+x]){c[2]++;y--;} if(y<0) return null;
        while(y>=0 && !bin[y*w+x] && c[1]<=maxCount){c[1]++;y--;} if(y<0||c[1]>maxCount) return null;
        while(y>=0 && bin[y*w+x] && c[0]<=maxCount){c[0]++;y--;} if(c[0]>maxCount) return null;
        y=Math.round(startY)+1;
        while(y<h && bin[y*w+x]){c[2]++;y++;} if(y===h) return null;
        while(y<h && !bin[y*w+x] && c[3]<maxCount){c[3]++;y++;} if(y===h||c[3]>=maxCount) return null;
        while(y<h && bin[y*w+x] && c[4]<maxCount){c[4]++;y++;} if(c[4]>=maxCount) return null;
        const total=c.reduce((a,b)=>a+b,0);
        if(Math.abs(total-origTotal) > origTotal*0.55 || !pattern5(c)) return null;
        return {center:centerFromEnd(c,y), total, module:total/7};
    }
    function crossHorizontal(bin,w,h,cy,startX,maxCount,origTotal) {
        const y=Math.round(cy); if(y<0||y>=h) return null;
        const c=[0,0,0,0,0]; let x=Math.round(startX);
        while(x>=0 && bin[y*w+x]){c[2]++;x--;} if(x<0) return null;
        while(x>=0 && !bin[y*w+x] && c[1]<=maxCount){c[1]++;x--;} if(x<0||c[1]>maxCount) return null;
        while(x>=0 && bin[y*w+x] && c[0]<=maxCount){c[0]++;x--;} if(c[0]>maxCount) return null;
        x=Math.round(startX)+1;
        while(x<w && bin[y*w+x]){c[2]++;x++;} if(x===w) return null;
        while(x<w && !bin[y*w+x] && c[3]<maxCount){c[3]++;x++;} if(x===w||c[3]>=maxCount) return null;
        while(x<w && bin[y*w+x] && c[4]<maxCount){c[4]++;x++;} if(c[4]>=maxCount) return null;
        const total=c.reduce((a,b)=>a+b,0);
        if(Math.abs(total-origTotal) > origTotal*0.55 || !pattern5(c)) return null;
        return {center:centerFromEnd(c,x), total, module:total/7};
    }
    function addCandidate(arr,x,y,module) {
        for(const p of arr) {
            const d=Math.hypot(p.x-x,p.y-y);
            if(d <= Math.max(3,p.module*2.2) && Math.abs(p.module-module) <= Math.max(2,p.module*0.65)) {
                const n=p.count+1;
                p.x=(p.x*p.count+x)/n; p.y=(p.y*p.count+y)/n; p.module=(p.module*p.count+module)/n; p.count=n;
                return;
            }
        }
        arr.push({x,y,module,count:1});
    }
    function findFinders(bin,w,h) {
        const out=[];
        const step = Math.max(1, Math.floor(Math.min(w,h)/900));
        for(let y=0;y<h;y+=step) {
            const runs=[]; let color=bin[y*w] ? 1:0, start=0;
            for(let x=1;x<=w;x++) {
                const v=x<w ? (bin[y*w+x]?1:0) : 2;
                if(v===color) continue;
                runs.push({color,len:x-start,end:x});
                if(runs.length>=5) {
                    const a=runs.slice(-5);
                    if(a[0].color===1&&a[1].color===0&&a[2].color===1&&a[3].color===0&&a[4].color===1) {
                        const c=a.map(r=>r.len);
                        if(pattern5(c)) {
                            const total=c.reduce((s,n)=>s+n,0);
                            const cx=centerFromEnd(c,x);
                            const cv=crossVertical(bin,w,h,y,cx,Math.max(...c)*2,total);
                            if(cv) {
                                const ch=crossHorizontal(bin,w,h,cv.center,cx,Math.max(...c)*2,total);
                                if(ch) addCandidate(out,ch.center,cv.center,(cv.module+ch.module+total/7)/3);
                            }
                        }
                    }
                }
                color=v; start=x;
                if(runs.length>7) runs.shift();
            }
        }
        return out.filter(p=>p.count>=1).sort((a,b)=>b.count-a.count);
    }
    function dist2(a,b){const x=a.x-b.x,y=a.y-b.y;return x*x+y*y;}
    function orientTriplet(a,b,c) {
        const dAB=dist2(a,b),dAC=dist2(a,c),dBC=dist2(b,c);
        let tl,u,v,diag;
        if(dBC>=dAB&&dBC>=dAC){tl=a;u=b;v=c;diag=dBC;}
        else if(dAC>=dAB&&dAC>=dBC){tl=b;u=a;v=c;diag=dAC;}
        else{tl=c;u=a;v=b;diag=dAB;}
        let tr=u,bl=v;
        const cross=(tr.x-tl.x)*(bl.y-tl.y)-(tr.y-tl.y)*(bl.x-tl.x);
        if(cross<0){const t=tr;tr=bl;bl=t;}
        const s1=Math.sqrt(dist2(tl,tr)),s2=Math.sqrt(dist2(tl,bl));
        const module=(tl.module+tr.module+bl.module)/3;
        const modules=(s1+s2)/(2*module);
        const rightErr=Math.abs(diag-(s1*s1+s2*s2))/(s1*s1+s2*s2);
        const sideRatio=Math.max(s1,s2)/Math.max(1,Math.min(s1,s2));
        return {tl,tr,bl,module,s1,s2,modules,rightErr,sideRatio};
    }
    function chooseGeometries(points) {
        const pts=points.slice(0,Math.min(12,points.length));
        const cand=[];
        for(let i=0;i<pts.length;i++)for(let j=i+1;j<pts.length;j++)for(let k=j+1;k<pts.length;k++){
            const g=orientTriplet(pts[i],pts[j],pts[k]);
            if(g.sideRatio>1.65||g.rightErr>0.5||g.modules<23||g.modules>38) continue;
            g.score=(pts[i].count+pts[j].count+pts[k].count)*8 - g.rightErr*25 - Math.abs(g.modules-30)*2 - (g.sideRatio-1)*10;
            cand.push(g);
        }
        return cand.sort((a,b)=>b.score-a.score).slice(0,4);
    }

    function sampleGray(gray,w,h,x,y) {
        const xi=Math.round(x),yi=Math.round(y);
        if(xi<0||yi<0||xi>=w||yi>=h) return 255;
        return gray[yi*w+xi];
    }
    function alignmentExpected(r,c){
        const d=Math.max(Math.abs(r-2),Math.abs(c-2));
        return d!==1 ? 1:0;
    }
    function findAlignment(gray,w,h,g,threshold) {
        const ex0={x:(g.tr.x-g.tl.x)/30,y:(g.tr.y-g.tl.y)/30};
        const ey0={x:(g.bl.x-g.tl.x)/30,y:(g.bl.y-g.tl.y)/30};
        const pred={x:g.tl.x+27*ex0.x+27*ey0.x,y:g.tl.y+27*ex0.y+27*ey0.y};
        const mod=Math.max(2,(Math.hypot(ex0.x,ex0.y)+Math.hypot(ey0.x,ey0.y))/2);
        let best=null;
        const radius=mod*8.0, step=Math.max(1.5,mod*0.45);
        const scales=[0.80,1.00,1.20,1.40,1.60];
        for (const scale of scales) {
            const ex={x:ex0.x*scale,y:ex0.y*scale};
            const ey={x:ey0.x*scale,y:ey0.y*scale};
            for(let dy=-radius;dy<=radius;dy+=step) for(let dx=-radius;dx<=radius;dx+=step) {
                const cx=pred.x+dx,cy=pred.y+dy; let bad=0,contrast=0;
                for(let r=0;r<5;r++)for(let c=0;c<5;c++){
                    const px=cx+(c-2)*ex.x+(r-2)*ey.x;
                    const py=cy+(c-2)*ex.y+(r-2)*ey.y;
                    const lum=sampleGray(gray,w,h,px,py);
                    const dark=lum<threshold?1:0,exp=alignmentExpected(r,c);
                    if(dark!==exp) bad++;
                    contrast += exp ? (255-lum) : lum;
                }
                const distancePenalty=(dx*dx+dy*dy)/(mod*mod)*3;
                const score=bad*1000-contrast+distancePenalty;
                if(!best||score<best.score) best={x:cx,y:cy,bad,score,scale};
            }
        }
        return best&&best.bad<=7 ? best:null;
    }

    function solveLinear(A,b) {
        const n=b.length, M=A.map((r,i)=>r.slice().concat([b[i]]));
        for(let col=0;col<n;col++){
            let pivot=col; for(let r=col+1;r<n;r++) if(Math.abs(M[r][col])>Math.abs(M[pivot][col])) pivot=r;
            if(Math.abs(M[pivot][col])<1e-10) return null;
            [M[col],M[pivot]]=[M[pivot],M[col]];
            const d=M[col][col]; for(let c=col;c<=n;c++) M[col][c]/=d;
            for(let r=0;r<n;r++) if(r!==col){const f=M[r][col];if(!f)continue;for(let c=col;c<=n;c++)M[r][c]-=f*M[col][c];}
        }
        return M.map((r,i)=>r[n]);
    }
    function homography(pairs) {
        const A=[],b=[];
        for(const p of pairs){const u=p.u,v=p.v,x=p.x,y=p.y;A.push([u,v,1,0,0,0,-x*u,-x*v]);b.push(x);A.push([0,0,0,u,v,1,-y*u,-y*v]);b.push(y);}
        const h=solveLinear(A,b); if(!h)return null;
        return (u,v)=>{const d=h[6]*u+h[7]*v+1;return{x:(h[0]*u+h[1]*v+h[2])/d,y:(h[3]*u+h[4]*v+h[5])/d};};
    }
    function affine(g,swap) {
        const tr=swap?g.bl:g.tr, bl=swap?g.tr:g.bl;
        return (u,v)=>({x:g.tl.x+((u-3)/30)*(tr.x-g.tl.x)+((v-3)/30)*(bl.x-g.tl.x),y:g.tl.y+((u-3)/30)*(tr.y-g.tl.y)+((v-3)/30)*(bl.y-g.tl.y)});
    }
    function matrixFromTransform(gray,w,h,transform,threshold) {
        const m=Array.from({length:N},()=>new Uint8Array(N));
        for(let r=0;r<N;r++)for(let c=0;c<N;c++){
            const samples=[[0,0],[.18,0],[-.18,0],[0,.18],[0,-.18]]; let sum=0;
            for(const [du,dv] of samples){const p=transform(c+du,r+dv);sum+=sampleGray(gray,w,h,p.x,p.y);}
            m[r][c]=(sum/samples.length)<threshold?1:0;
        }
        return m;
    }

    function rgbaToGray(data) {
        const g=new Uint8Array(data.length/4);
        for(let i=0,j=0;i<data.length;i+=4,j++) g[j]=Math.round(data[i]*0.299+data[i+1]*0.587+data[i+2]*0.114);
        return g;
    }

    function decodeImageData(imageData) {
        const w=imageData.width,h=imageData.height;
        if(w<120||h<120) return null;
        const gray=rgbaToGray(imageData.data);
        const base=otsu(gray);
        const finderThresholds=[base,Math.max(25,base-18),Math.min(230,base+18)];
        let geometries=[];
        for(const t of finderThresholds){
            const pts=findFinders(binaryFromGray(gray,t),w,h);
            const gs=chooseGeometries(pts);
            geometries=geometries.concat(gs.map(g=>Object.assign({finderThreshold:t},g)));
            if(geometries.length>=3) break;
        }
        if(!geometries.length) return null;
        const thresholds=[base,Math.max(20,base-24),Math.min(235,base+24),Math.max(20,base-12),Math.min(235,base+12)];
        for(const g of geometries.slice(0,5)) {
            const align=findAlignment(gray,w,h,g,g.finderThreshold||base);
            const transforms=[];
            if(align){
                const H=homography([{u:3,v:3,x:g.tl.x,y:g.tl.y},{u:33,v:3,x:g.tr.x,y:g.tr.y},{u:3,v:33,x:g.bl.x,y:g.bl.y},{u:30,v:30,x:align.x,y:align.y}]);
                if(H) transforms.push(H);
            }
            transforms.push(affine(g,false));
            transforms.push(affine(g,true));
            for(const tf of transforms) for(const t of thresholds) {
                const payload=decodeMatrix(matrixFromTransform(gray,w,h,tf,t));
                if(payload) return payload;
            }
        }
        return null;
    }

    function decodeCanvas(canvas) {
        try {
            const ctx=canvas.getContext('2d',{willReadFrequently:true});
            return decodeImageData(ctx.getImageData(0,0,canvas.width,canvas.height));
        } catch (_) { return null; }
    }

    global.SiQrPhotoReader={decodeCanvas,decodeImageData,decodeMatrix};
})(typeof window!=='undefined'?window:this);
