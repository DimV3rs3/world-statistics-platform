(function() {
    var el = document.getElementById('wsp-explorer-map');
    if (!el) return;
    if (typeof L === 'undefined') { setTimeout(arguments.callee, 150); return; }
    if (typeof topojson === 'undefined') { setTimeout(arguments.callee, 150); return; }
    if (el._leaflet_id) return;

    var D = window.wspExplorerData || {};
    var layerValues = D.layerData || {};
    var layerConfig = D.layerConfig;
    var hasLayer = layerConfig && Object.keys(layerValues).length > 0;

    // === Цвета регионов (как в mini-map) ===
    var COLORS = {
        europe:'#5B8FD6', asia:'#5EAF5E', africa:'#F0A830',
        north_america:'#E05555', south_america:'#9B59B6',
        oceania:'#1ABC9C', antarctica:'#D5E8F0', _default:'#B0BEC5'
    };
    var ID_REGION = {};
    [8,20,40,51,56,70,100,112,191,196,203,208,233,234,246,250,268,276,292,300,336,348,352,372,380,428,438,440,442,470,492,498,499,528,578,616,620,642,643,674,688,703,705,724,752,756,804,807,826,383].forEach(function(n){ID_REGION[n]='europe';});
    [4,31,48,50,64,96,104,116,144,156,344,356,360,364,368,376,392,398,400,408,410,414,417,418,422,458,462,496,512,524,586,608,634,682,702,760,762,764,784,792,795,860,704,887,158,275,626].forEach(function(n){ID_REGION[n]='asia';});
    [12,24,72,108,120,132,140,148,174,175,178,180,204,226,231,232,262,266,270,288,324,384,404,426,430,434,450,454,466,478,480,504,508,516,562,566,624,646,686,690,694,706,710,716,728,729,748,768,788,800,834,854,894,732,818,638].forEach(function(n){ID_REGION[n]='africa';});
    [28,44,52,84,124,136,188,192,212,214,222,304,308,312,320,332,340,388,474,484,533,534,535,558,591,630,652,659,660,662,663,670,780,796,840,850].forEach(function(n){ID_REGION[n]='north_america';});
    [32,68,76,152,170,218,238,254,328,600,604,740,858,862].forEach(function(n){ID_REGION[n]='south_america';});
    [36,90,242,258,296,520,540,548,554,570,574,580,581,582,583,584,585,598,776,798,882].forEach(function(n){ID_REGION[n]='oceania';});
    [10,260].forEach(function(n){ID_REGION[n]='antarctica';});

    var ID_A2 = {4:'AF',8:'AL',12:'DZ',16:'AS',20:'AD',24:'AO',28:'AG',32:'AR',36:'AU',40:'AT',31:'AZ',44:'BS',48:'BH',50:'BD',51:'AM',52:'BB',56:'BE',64:'BT',68:'BO',70:'BA',72:'BW',76:'BR',84:'BZ',90:'SB',96:'BN',100:'BG',104:'MM',108:'BI',112:'BY',116:'KH',120:'CM',124:'CA',132:'CV',136:'KY',140:'CF',144:'LK',148:'TD',152:'CL',156:'CN',158:'TW',170:'CO',174:'KM',175:'YT',178:'CG',180:'CD',188:'CR',191:'HR',192:'CU',196:'CY',203:'CZ',204:'BJ',208:'DK',212:'DM',214:'DO',218:'EC',222:'SV',226:'GQ',231:'ET',232:'ER',233:'EE',234:'FO',238:'FK',242:'FJ',246:'FI',250:'FR',254:'GF',258:'PF',262:'DJ',266:'GA',268:'GE',270:'GM',275:'PS',276:'DE',288:'GH',292:'GI',296:'KI',300:'GR',304:'GL',308:'GD',312:'GP',320:'GT',324:'GN',328:'GY',332:'HT',336:'VA',340:'HN',344:'HK',348:'HU',352:'IS',356:'IN',360:'ID',364:'IR',368:'IQ',372:'IE',376:'IL',380:'IT',384:'CI',388:'JM',392:'JP',398:'KZ',400:'JO',404:'KE',408:'KP',410:'KR',414:'KW',417:'KG',418:'LA',422:'LB',426:'LS',428:'LV',430:'LR',434:'LY',438:'LI',440:'LT',442:'LU',450:'MG',454:'MW',458:'MY',462:'MV',466:'ML',470:'MT',478:'MR',480:'MU',484:'MX',492:'MC',496:'MN',498:'MD',499:'ME',504:'MA',508:'MZ',512:'OM',516:'NA',520:'NR',524:'NP',528:'NL',540:'NC',548:'VU',554:'NZ',558:'NI',562:'NE',566:'NG',570:'NU',574:'NF',578:'NO',580:'MP',581:'UM',582:'MH',583:'FM',584:'PW',585:'PW',586:'PK',591:'PA',598:'PG',600:'PY',604:'PE',608:'PH',616:'PL',620:'PT',624:'GW',626:'TL',630:'PR',634:'QA',642:'RO',643:'RU',646:'RW',652:'BL',659:'KN',660:'AI',662:'LC',663:'MF',670:'VC',674:'SM',678:'ST',682:'SA',686:'SN',688:'RS',690:'SC',694:'SL',702:'SG',703:'SK',704:'VN',705:'SI',706:'SO',710:'ZA',716:'ZW',724:'ES',728:'SS',729:'SD',740:'SR',748:'SZ',752:'SE',756:'CH',760:'SY',762:'TJ',764:'TH',768:'TG',776:'TO',780:'TT',784:'AE',788:'TN',792:'TR',795:'TM',796:'TC',798:'TV',800:'UG',804:'UA',807:'MK',818:'EG',826:'GB',834:'TZ',840:'US',850:'VI',854:'BF',858:'UY',860:'UZ',862:'VE',876:'WF',882:'WS',887:'YE',894:'ZM',10:'AQ',732:'EH',383:'XK',638:'RE',260:'TF'};

    function getNumId(f){var r=f.id!==undefined?f.id:(f.properties?f.properties.id:0);return parseInt(r,10)||0;}
    function getA2(n){return ID_A2[n]||'';}
    function getRegion(n){return ID_REGION[n]||'_default';}
    function getColor(n){return COLORS[getRegion(n)]||COLORS._default;}

    // === Хороплет ===
    var vals = Object.values(layerValues).filter(function(v){return typeof v==='number' && isFinite(v);});
    var vMin = vals.length ? Math.min.apply(null, vals) : 0;
    var vMax = vals.length ? Math.max.apply(null, vals) : 100;
    var vRng = vMax - vMin || 1;

    function choroColor(val) {
        if (val === undefined || val === null || isNaN(val)) return '#e0e0e0';
        var t = Math.max(0, Math.min(1, (val - vMin) / vRng));
        var r, g, b;
        if (t < 0.5) { var s = t * 2; r = Math.round(34 + s * (234 - 34)); g = Math.round(197 + s * (179 - 197)); b = Math.round(94 + s * (8 - 94)); }
        else { var s = (t - 0.5) * 2; r = Math.round(234 + s * (239 - 234)); g = Math.round(179 + s * (68 - 179)); b = Math.round(8 + s * (68 - 8)); }
        return 'rgb('+r+','+g+','+b+')';
    }

    function formatVal(v) {
        if (typeof v !== 'number') return String(v);
        if (Math.abs(v) >= 1e6) return (v/1e6).toFixed(1)+'M';
        if (Math.abs(v) >= 1e3) return (v/1e3).toFixed(1)+'K';
        return v.toFixed(v%1===0?0:2);
    }

    // Тултип
    var tip = document.getElementById('wsp-map-tooltip');
    function showTip(e, name, val) {
        if (!tip) return;
        tip.innerHTML = '<div class="tooltip-country">' + name + '</div>' + (val !== undefined ? '<div class="tooltip-value">' + formatVal(val) + '</div>' : '');
        tip.style.display = 'block';
        tip.style.left = e.originalEvent.clientX + 'px';
        tip.style.top = (e.originalEvent.clientY - 10) + 'px';
    }
    function hideTip() { if (tip) tip.style.display = 'none'; }

    // === Карта (как в mini-map) ===
    var map = L.map('wsp-explorer-map', {
        zoomControl: true,
        attributionControl: false,
        maxBoundsViscosity: 1.0,
        renderer: L.svg()
    }).setView([20, 0], 2);

    // === Загрузка стран ===
    var url = (window.wspThemeUrl || '') + '/assets/data/countries-110m.json';

    function fixAntimeridian(geojson){
        geojson.features.forEach(function(f){
            if(!f.geometry)return;
            var t=f.geometry.type;
            function fixRing(ring){
                var hasH=false,hasL=false;
                for(var i=0;i<ring.length;i++){if(ring[i][0]>160)hasH=true;if(ring[i][0]<-160)hasL=true;}
                if(hasH&&hasL){for(var j=0;j<ring.length;j++){if(ring[j][0]<0)ring[j][0]+=360;}}
            }
            if(t==='Polygon')f.geometry.coordinates.forEach(fixRing);
            else if(t==='MultiPolygon')f.geometry.coordinates.forEach(function(p){p.forEach(fixRing);});
        });
        return geojson;
    }

    function splitOverseas(geojson){
        var extra=[];
        geojson.features.forEach(function(f){
            if(!f.geometry||f.geometry.type!=='MultiPolygon')return;
            var numId=getNumId(f);
            if(numId===250){
                var keep=[],split=[];
                f.geometry.coordinates.forEach(function(poly){
                    var ring=poly[0],sx=0,n=ring.length;
                    for(var i=0;i<n;i++)sx+=ring[i][0];
                    if(sx/n<-30)split.push(poly);else keep.push(poly);
                });
                if(split.length>0){
                    f.geometry.coordinates=keep;
                    if(keep.length===1){f.geometry.type='Polygon';f.geometry.coordinates=keep[0];}
                    extra.push({type:'Feature',id:'254',properties:{name:'French Guiana'},geometry:split.length===1?{type:'Polygon',coordinates:split[0]}:{type:'MultiPolygon',coordinates:split}});
                }
            }
        });
        for(var i=0;i<extra.length;i++)geojson.features.push(extra[i]);
        return geojson;
    }

    fetch(url)
        .then(function(r){return r.json();})
        .then(function(topo){
            var geo = topojson.feature(topo, topo.objects.countries);
            splitOverseas(geo);
            fixAntimeridian(geo);

            var geoLayer = L.geoJSON(geo, {
                style: function(f){
    var a2 = getA2(getNumId(f));
    var val = layerValues[a2];
    
    if (hasLayer) {
        // Слой — только хороплет, без региональных цветов
        return {
            fillColor: val !== undefined ? choroColor(val) : '#e0e0e0',
            weight: 0.5, color: '#fff', opacity: 1, fillOpacity: 0.85
        };
    }
    
    // Нет слоя — региональные цвета
    return {
        fillColor: getColor(getNumId(f)),
        weight: 0.5, color: '#fff', opacity: 1, fillOpacity: 0.75
    };
},
                onEachFeature: function(feature, layer){
    var numId = getNumId(feature);
    var a2 = getA2(numId);
    if (!a2 || a2 === 'AQ') return;
    
    // Если выбрана метрика (не слой) — показываем значение на стране
    if (!hasLayer && Object.keys(layerValues).length > 0) {
        var val = layerValues[a2];
        if (val !== undefined) {
            var label = formatVal(val);
            layer.bindTooltip(label, {
                permanent: true,        // всегда видно
                direction: 'center',
                className: 'wsp-country-label',
                opacity: 0.9
            });
        }
    }
    
    layer.on({
        mouseover: function(e){
            e.target.setStyle({weight:2,color:'#2C3E50',fillOpacity:0.95});
            if(!L.Browser.ie&&!L.Browser.opera&&!L.Browser.edge)e.target.bringToFront();
            var name = (D.names && D.names[a2]) || a2;
            showTip(e, name, layerValues[a2]);
        },
        mouseout: function(e){ geoLayer.resetStyle(e.target); hideTip(); },
        mousemove: function(e){
            var name = (D.names && D.names[a2]) || a2;
            showTip(e, name, layerValues[a2]);
        },
        click: function(){
            if (D.urls && D.urls[a2]) window.location.href = D.urls[a2];
        }
    });
}
            }).addTo(map);

            setTimeout(function() { map.invalidateSize(); }, 100);

            if (hasLayer) {
                var legend = document.getElementById('wsp-legend');
                if (legend) legend.classList.add('visible');
            }
        })
        .catch(function(e){ console.error('Map load error:', e); });
})();

function wspSwitchMetric(val) {
    var url = new URL(window.location);
    url.searchParams.delete('layer');
    if (val) { url.searchParams.set('metric', val); }
    else { url.searchParams.delete('metric'); }
    window.location = url.toString();
}
function wspSwitchLayer(val) {
    var url = new URL(window.location);
    url.searchParams.delete('metric');
    if (val) { url.searchParams.set('layer', val); }
    else { url.searchParams.delete('layer'); }
    window.location = url.toString();
}