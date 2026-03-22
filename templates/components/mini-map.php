<?php
/**
 * Leaflet mini-map component with coordinate grid & extension marker layers.
 *
 * Supports two rendering modes:
 *   - Tile-based: OSM, Carto-light, Carto-dark
 *   - Countries:  Same TopoJSON polygons + regional colors as the homepage map
 *
 * Variables available: $opts (array) — passed from WorldStat_UI::map().
 *
 * @package WorldStatPlatform
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$map_id  = 'wsp-minimap-' . wp_unique_id();
$fn_safe = preg_replace( '/[^a-z0-9_]/i', '_', $map_id );

/* ── Assets ──────────────────────────────────────────────── */
wp_enqueue_style( 'leaflet', WSP_ASSETS_URL . 'vendor/leaflet/leaflet.css', [], '1.9' );
wp_enqueue_script( 'leaflet', WSP_ASSETS_URL . 'vendor/leaflet/leaflet.js', [], '1.9', true );

/* ── Tile URL by style ───────────────────────────────────── */
$tile_urls = [
	'osm'         => 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
	'carto-light' => 'https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png',
	'carto-dark'  => 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png',
];
$tile_style     = $opts['tile_style'] ?? 'carto-light';
$is_countries   = ( $tile_style === 'countries' );
$tile_url       = $is_countries ? '' : ( $tile_urls[ $tile_style ] ?? $tile_urls['carto-light'] );

/* TopoJSON URL from theme for "countries" mode */
$topojson_url = '';
if ( $is_countries ) {
	$topojson_url = get_template_directory_uri() . '/assets/data/countries-110m.json';
	wp_enqueue_script( 'topojson-client', get_template_directory_uri() . '/assets/vendor/topojson/topojson-client.min.js', [], '3.1.0', true );
}

/* Country URLs for click navigation */
$country_urls_json = '{}';
if ( $is_countries ) {
	$map_data          = worldstat_platform()->map->get_country_map_data();
	$country_urls_json = wp_json_encode( $map_data['urls'] ?? [] );
}

/* ── Collect extension marker layers ─────────────────────── */
$ext_marker_layers = [];
if ( ! empty( $opts['marker_layers'] ) ) {
	$platform = worldstat_platform();
	$all_marker_layers = $platform->extensions->get_marker_layers();
	$requested = $opts['marker_layers'];
	$country   = $opts['country'] ?? '';

	foreach ( $all_marker_layers as $i => $ml ) {
		$layer_id = $ml['ext_id'] . '_markers_' . $i;

		if ( in_array( 'all', $requested, true ) || in_array( $layer_id, $requested, true ) || in_array( $ml['ext_id'], $requested, true ) ) {
			$markers = $platform->map->get_marker_data( $layer_id, $country );
			if ( ! empty( $markers ) ) {
				$ext_marker_layers[] = [
					'id'      => $layer_id,
					'label'   => $ml['label'],
					'icon'    => $ml['icon'],
					'color'   => $ml['color'],
					'radius'  => (int) $ml['radius'],
					'markers' => $markers,
				];
			}
		}
	}
}

$show_grid     = ! empty( $opts['grid'] );
$grid_interval = (int) ( $opts['grid_interval'] ?? 15 );
$grid_labels   = ! empty( $opts['grid_labels'] );
$layer_control = ! empty( $opts['layer_control'] ) && ( count( $ext_marker_layers ) > 0 || ! empty( $opts['markers'] ) || $show_grid );
$highlight_iso = strtoupper( $opts['country'] ?? '' );
?>
<div class="wsp-minimap-wrap">
    <div id="<?php echo esc_attr( $map_id ); ?>" class="wsp-minimap" style="height:<?php echo (int) $opts['height']; ?>px;background:#d4e6f1;"></div>
    <script>
    (function(){
    function wspInitMap_<?php echo $fn_safe; ?>() {
        if (typeof L === 'undefined') { setTimeout(wspInitMap_<?php echo $fn_safe; ?>, 150); return; }
        <?php if ( $is_countries ) : ?>
        if (typeof topojson === 'undefined') { setTimeout(wspInitMap_<?php echo $fn_safe; ?>, 150); return; }
        <?php endif; ?>
        var el = document.getElementById('<?php echo esc_js( $map_id ); ?>');
        if (!el || el._leaflet_id) return;

        /* ── Map init ───────────────────────────────────── */
        var m = L.map('<?php echo esc_js( $map_id ); ?>', {
            zoomControl: true,
            attributionControl: false,
            maxBoundsViscosity: 1.0,
            renderer: L.svg()
        }).setView(
            [<?php echo (float) $opts['lat']; ?>, <?php echo (float) $opts['lng']; ?>],
            <?php echo (int) $opts['zoom']; ?>
        );

        var overlays = {};

        <?php if ( $is_countries ) : ?>
        /* ══════════════════════════════════════════════════
           COUNTRIES BASE LAYER (same style as homepage)
        ══════════════════════════════════════════════════ */
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

        var ID_A2={4:'AF',8:'AL',12:'DZ',16:'AS',20:'AD',24:'AO',28:'AG',32:'AR',36:'AU',40:'AT',31:'AZ',44:'BS',48:'BH',50:'BD',51:'AM',52:'BB',56:'BE',64:'BT',68:'BO',70:'BA',72:'BW',76:'BR',84:'BZ',90:'SB',96:'BN',100:'BG',104:'MM',108:'BI',112:'BY',116:'KH',120:'CM',124:'CA',132:'CV',136:'KY',140:'CF',144:'LK',148:'TD',152:'CL',156:'CN',158:'TW',170:'CO',174:'KM',175:'YT',178:'CG',180:'CD',188:'CR',191:'HR',192:'CU',196:'CY',203:'CZ',204:'BJ',208:'DK',212:'DM',214:'DO',218:'EC',222:'SV',226:'GQ',231:'ET',232:'ER',233:'EE',234:'FO',238:'FK',242:'FJ',246:'FI',250:'FR',254:'GF',258:'PF',262:'DJ',266:'GA',268:'GE',270:'GM',275:'PS',276:'DE',288:'GH',292:'GI',296:'KI',300:'GR',304:'GL',308:'GD',312:'GP',320:'GT',324:'GN',328:'GY',332:'HT',336:'VA',340:'HN',344:'HK',348:'HU',352:'IS',356:'IN',360:'ID',364:'IR',368:'IQ',372:'IE',376:'IL',380:'IT',384:'CI',388:'JM',392:'JP',398:'KZ',400:'JO',404:'KE',408:'KP',410:'KR',414:'KW',417:'KG',418:'LA',422:'LB',426:'LS',428:'LV',430:'LR',434:'LY',438:'LI',440:'LT',442:'LU',450:'MG',454:'MW',458:'MY',462:'MV',466:'ML',470:'MT',478:'MR',480:'MU',484:'MX',492:'MC',496:'MN',498:'MD',499:'ME',504:'MA',508:'MZ',512:'OM',516:'NA',520:'NR',524:'NP',528:'NL',540:'NC',548:'VU',554:'NZ',558:'NI',562:'NE',566:'NG',570:'NU',574:'NF',578:'NO',580:'MP',581:'UM',582:'MH',583:'FM',584:'PW',585:'PW',586:'PK',591:'PA',598:'PG',600:'PY',604:'PE',608:'PH',616:'PL',620:'PT',624:'GW',626:'TL',630:'PR',634:'QA',642:'RO',643:'RU',646:'RW',652:'BL',659:'KN',660:'AI',662:'LC',663:'MF',670:'VC',674:'SM',678:'ST',682:'SA',686:'SN',688:'RS',690:'SC',694:'SL',702:'SG',703:'SK',704:'VN',705:'SI',706:'SO',710:'ZA',716:'ZW',724:'ES',728:'SS',729:'SD',740:'SR',748:'SZ',752:'SE',756:'CH',760:'SY',762:'TJ',764:'TH',768:'TG',776:'TO',780:'TT',784:'AE',788:'TN',792:'TR',795:'TM',796:'TC',798:'TV',800:'UG',804:'UA',807:'MK',818:'EG',826:'GB',834:'TZ',840:'US',850:'VI',854:'BF',858:'UY',860:'UZ',862:'VE',876:'WF',882:'WS',887:'YE',894:'ZM',10:'AQ',732:'EH',383:'XK',638:'RE',260:'TF'};

        var countryUrls = <?php echo $country_urls_json; ?>;
        var highlightISO = '<?php echo esc_js( $highlight_iso ); ?>';

        function getNumId(f){var r=f.id!==undefined?f.id:(f.properties?f.properties.id:0);return parseInt(r,10)||0;}
        function getA2(n){return ID_A2[n]||'';}
        function getRegion(n){return ID_REGION[n]||'_default';}
        function getColor(n){return COLORS[getRegion(n)]||COLORS._default;}

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

        fetch('<?php echo esc_js( $topojson_url ); ?>')
            .then(function(r){return r.json();})
            .then(function(topo){
                var geo = topojson.feature(topo, topo.objects.countries);
                splitOverseas(geo);
                fixAntimeridian(geo);

                var geoLayer = L.geoJSON(geo, {
                    style: function(feature){
                        var numId=getNumId(feature);
                        var a2=getA2(numId);
                        var isHighlight = highlightISO && a2===highlightISO;
                        return {
                            fillColor: getColor(numId),
                            weight: isHighlight ? 2.5 : 0.8,
                            color: isHighlight ? '#1e293b' : '#fff',
                            opacity: 1,
                            fillOpacity: isHighlight ? 0.95 : 0.75
                        };
                    },
                    onEachFeature: function(feature, layer){
                        var numId=getNumId(feature);
                        var a2=getA2(numId);
                        if(!a2||a2==='AQ')return;
                        layer.on({
                            mouseover: function(e){
                                e.target.setStyle({weight:2,color:'#2C3E50',fillOpacity:0.95});
                                if(!L.Browser.ie&&!L.Browser.opera&&!L.Browser.edge)e.target.bringToFront();
                            },
                            mouseout: function(e){ geoLayer.resetStyle(e.target); },
                            click: function(){
                                if(countryUrls[a2]){window.location.href=countryUrls[a2];}
                            }
                        });
                    }
                }).addTo(m);

                /* Bring markers to front after GeoJSON is loaded */
                m.eachLayer(function(l){ if(l._leaflet_id && l.options && l.options.pane==='markerPane') l.bringToFront(); });
            })
            .catch(function(e){console.error('WSP map data error:',e);});

        <?php else : ?>
        /* ── Tile-based base layer ──────────────────────── */
        L.tileLayer('<?php echo esc_js( $tile_url ); ?>', {
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OSM</a>',
            maxZoom: 18
        }).addTo(m);
        <?php endif; ?>

        <?php if ( $show_grid ) : ?>
        /* ══════════════════════════════════════════════════
           COORDINATE GRID (Graticule)
        ══════════════════════════════════════════════════ */
        var gratGroup = L.layerGroup().addTo(m);

        function drawGraticule() {
            gratGroup.clearLayers();
            var interval = <?php echo $grid_interval; ?>;
            var gridStyle = { color: '#667788', weight: 0.6, opacity: 0.35, dashArray: '4 4', interactive: false };

            for (var lat = -90 + interval; lat < 90; lat += interval) {
                L.polyline([[lat, -180], [lat, 180]], gridStyle).addTo(gratGroup);
            }
            L.polyline([[0, -180], [0, 180]], { color: '#667788', weight: 1.2, opacity: 0.5, dashArray: '', interactive: false }).addTo(gratGroup);

            for (var lng = -180; lng <= 180; lng += interval) {
                L.polyline([[-90, lng], [90, lng]], gridStyle).addTo(gratGroup);
            }
            L.polyline([[-90, 0], [90, 0]], { color: '#667788', weight: 1.2, opacity: 0.5, dashArray: '', interactive: false }).addTo(gratGroup);

            <?php if ( $grid_labels ) : ?>
            var zoom = m.getZoom();
            var labelInterval = interval;
            if (zoom >= 6) labelInterval = interval / 3;
            else if (zoom >= 4) labelInterval = interval / 2;

            var bounds = m.getBounds();
            var latS = Math.ceil(bounds.getSouth() / labelInterval) * labelInterval;
            var latE = Math.floor(bounds.getNorth() / labelInterval) * labelInterval;
            var lngS = Math.ceil(bounds.getWest() / labelInterval) * labelInterval;
            var lngE = Math.floor(bounds.getEast() / labelInterval) * labelInterval;

            for (var lat = latS; lat <= latE; lat += labelInterval) {
                if (lat === 0) continue;
                L.marker([lat, bounds.getWest() + 0.5], {
                    icon: L.divIcon({ className:'wsp-grid-label wsp-grid-label-lat', html:Math.abs(lat)+'°'+(lat>0?'N':'S'), iconSize:[40,14], iconAnchor:[0,7] }),
                    interactive: false
                }).addTo(gratGroup);
            }
            for (var lng = lngS; lng <= lngE; lng += labelInterval) {
                if (lng === 0) continue;
                L.marker([bounds.getSouth() + 0.5, lng], {
                    icon: L.divIcon({ className:'wsp-grid-label wsp-grid-label-lng', html:Math.abs(lng)+'°'+(lng>0?'E':'W'), iconSize:[44,14], iconAnchor:[22,14] }),
                    interactive: false
                }).addTo(gratGroup);
            }
            <?php endif; ?>
        }

        drawGraticule();
        m.on('moveend zoomend', drawGraticule);
        overlays['<?php echo esc_js( __( 'Координатная сетка', 'flavor-worldstat' ) ); ?>'] = gratGroup;
        <?php endif; ?>

        <?php
        /* ══════════════════════════════════════════════════
           MANUAL MARKERS (passed via $opts['markers'])
        ══════════════════════════════════════════════════ */
        if ( ! empty( $opts['markers'] ) ) : ?>
        var manualGroup = L.layerGroup().addTo(m);
        <?php foreach ( $opts['markers'] as $mk ) :
            $mlat   = (float) ( $mk['lat'] ?? 0 );
            $mlng   = (float) ( $mk['lng'] ?? 0 );
            $mtitle = esc_js( $mk['title'] ?? '' );
            $mpopup = esc_js( $mk['popup'] ?? $mk['title'] ?? '' );
            $mcolor = esc_js( $mk['color'] ?? '#3b82f6' );
            $mrad   = (int) ( $mk['radius'] ?? 6 );
        ?>
        L.circleMarker([<?php echo $mlat; ?>, <?php echo $mlng; ?>], {
            radius: <?php echo $mrad; ?>, fillColor: '<?php echo $mcolor; ?>', color: '#fff', weight: 2, fillOpacity: 0.85
        }).addTo(manualGroup).bindPopup('<?php echo $mpopup; ?>').bindTooltip('<?php echo $mtitle; ?>', {direction:'top',offset:[0,-<?php echo $mrad; ?>]});
        <?php endforeach; ?>
        overlays['Маркеры'] = manualGroup;
        <?php endif; ?>

        <?php
        /* ══════════════════════════════════════════════════
           EXTENSION MARKER LAYERS (pre-loaded server-side)
        ══════════════════════════════════════════════════ */
        foreach ( $ext_marker_layers as $el ) :
            $el_var   = 'ml_' . preg_replace( '/[^a-z0-9_]/', '_', $el['id'] );
            $el_json  = wp_json_encode( $el['markers'] );
            $el_color = esc_js( $el['color'] );
            $el_rad   = (int) $el['radius'];
            $el_icon  = $el['icon'];
        ?>
        var <?php echo $el_var; ?> = L.layerGroup().addTo(m);
        (function(layerGroup, markers, color, radius, iconType) {
            markers.forEach(function(mk) {
                var lat = parseFloat(mk.lat) || 0;
                var lng = parseFloat(mk.lng) || 0;
                if (!lat && !lng) return;

                var title = mk.title || '';
                var popup = mk.popup || title;
                var value = mk.value !== undefined ? mk.value : '';
                var mkColor = mk.color || color;
                var mkRadius = mk.radius ? parseInt(mk.radius) : radius;

                var popupHtml = '<div class="wsp-marker-popup-content"><strong>' + title + '</strong>';
                if (value !== '') popupHtml += '<br><span class="wsp-marker-value">' + value + '</span>';
                if (popup && popup !== title) popupHtml += '<br>' + popup;
                popupHtml += '</div>';

                if (iconType === 'pin') {
                    var pinIcon = L.divIcon({
                        className: 'wsp-pin-marker',
                        html: '<svg width="20" height="28" viewBox="0 0 20 28"><path d="M10 0C4.5 0 0 4.5 0 10c0 7.5 10 18 10 18s10-10.5 10-18C20 4.5 15.5 0 10 0z" fill="' + mkColor + '" stroke="#fff" stroke-width="1.5"/><circle cx="10" cy="10" r="4" fill="#fff" fill-opacity="0.9"/></svg>',
                        iconSize: [20, 28], iconAnchor: [10, 28], popupAnchor: [0, -28]
                    });
                    L.marker([lat, lng], {icon: pinIcon}).addTo(layerGroup).bindPopup(popupHtml).bindTooltip(title, {direction:'top',offset:[0,-28]});
                } else if (iconType === 'square') {
                    var size = mkRadius * 2;
                    var sqIcon = L.divIcon({
                        className: 'wsp-square-marker',
                        html: '<div style="width:'+size+'px;height:'+size+'px;background:'+mkColor+';border:2px solid #fff;border-radius:2px;opacity:0.85;"></div>',
                        iconSize: [size, size], iconAnchor: [size/2, size/2]
                    });
                    L.marker([lat, lng], {icon: sqIcon}).addTo(layerGroup).bindPopup(popupHtml).bindTooltip(title, {direction:'top',offset:[0,-mkRadius]});
                } else if (iconType === 'diamond') {
                    var dSize = mkRadius * 2;
                    var dIcon = L.divIcon({
                        className: 'wsp-diamond-marker',
                        html: '<div style="width:'+dSize+'px;height:'+dSize+'px;background:'+mkColor+';border:2px solid #fff;transform:rotate(45deg);opacity:0.85;"></div>',
                        iconSize: [dSize, dSize], iconAnchor: [dSize/2, dSize/2]
                    });
                    L.marker([lat, lng], {icon: dIcon}).addTo(layerGroup).bindPopup(popupHtml).bindTooltip(title, {direction:'top',offset:[0,-mkRadius]});
                } else {
                    L.circleMarker([lat, lng], {
                        radius: mkRadius, fillColor: mkColor, color: '#fff', weight: 2, fillOpacity: 0.85
                    }).addTo(layerGroup).bindPopup(popupHtml).bindTooltip(title, {direction:'top',offset:[0,-mkRadius]});
                }
            });
        })(<?php echo $el_var; ?>, <?php echo $el_json; ?>, '<?php echo $el_color; ?>', <?php echo $el_rad; ?>, '<?php echo esc_js( $el_icon ); ?>');
        overlays['<?php echo esc_js( $el['label'] ); ?>'] = <?php echo $el_var; ?>;
        <?php endforeach; ?>

        <?php if ( $layer_control ) : ?>
        L.control.layers(null, overlays, { collapsed: false, position: 'topright' }).addTo(m);
        <?php endif; ?>

        /* ── Coordinate display on mouse move ──────────── */
        var coordDiv = L.DomUtil.create('div', 'wsp-coord-display');
        coordDiv.style.cssText = 'position:absolute;bottom:4px;left:50%;transform:translateX(-50%);z-index:800;';
        el.appendChild(coordDiv);
        m.on('mousemove', function(e) {
            var lat = e.latlng.lat.toFixed(4), lng = e.latlng.lng.toFixed(4);
            coordDiv.innerHTML = Math.abs(lat) + '° ' + (e.latlng.lat>=0?'N':'S') + ', ' + Math.abs(lng) + '° ' + (e.latlng.lng>=0?'E':'W');
        });
        m.on('mouseout', function() { coordDiv.innerHTML = ''; });
    }
    if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',wspInitMap_<?php echo $fn_safe; ?>);}
    else{wspInitMap_<?php echo $fn_safe; ?>();}
    })();
    </script>
</div>
