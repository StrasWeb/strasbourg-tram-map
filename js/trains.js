/*global L, reqwest*/
/*jslint browser: true */
var map = null;
var trains = new L.LayerGroup([]);
//var train_by_id = new Array();
var starttime = new Date();
var extra = 0;

/* Useful global functions */

function setOpacity(m, o) {
    'use strict';
    m = m.style;
    if (typeof m.filter === 'string') {
        m.filter = 'alpha(opacity=' + (o * 100) + ')';
    } else {
        m.opacity = o;
        m['-moz-opacity'] = o;
        m['-khtml-opacity'] = o;
    }
}

function kb(a) {
    'use strict';
    var b = { "x": 0, "y": 0 };
    while (a) {
        b.x += a.offsetLeft;
        b.y += a.offsetTop;
        a = a.offsetParent;
    }
    return b;
}

var BaseIcon = L.Icon.extend({
    options: {
        shadowUrl: "http://traintimes.org.uk/map/tube/i/pin_shadow.png",
        shadowSize: [ 22, 20 ],
        shadowAnchor: [ 6, 20 ]
    }
});

var Station = L.Marker.extend({
    initialize: function (station, options) {
        'use strict';
        L.Marker.prototype.initialize.call(this, station.point, options);
        this.bindLabel(station.name);
    },
    options: {
        icon: new BaseIcon({
            iconUrl: "20px-Map_symbol-pin.svg.png",
            iconSize: [ 20, 28 ],
            iconAnchor: [ 0, 28 ],
            labelAnchor: [ 4, -13 ]
        })
    }
});

var Train = L.CircleMarker.extend({
    initialize: function (train, options) {
        'use strict';
        L.CircleMarker.prototype.initialize.call(this, train.point, {
            weight: 2,
            color: '#000',
            opacity: 1,
            radius: 5,
            fillColor: '#ff0',
            fillOpacity: 1
        });
        this.updateDetails(train);
        this.info = '';
        this.calculateLocation();
    },
    createTitle: function () {
        'use strict';
        var html = '';
        html = this.title + '<br>' + this.info;
        if (this.string) { html += '<br><em>' + this.string + '</em>'; }
        //if (html != this.getTooltip()) this.setTooltip(html);
        if (this.link) { html += '<br><a href="' + this.link + '">View board</a>'; }
        this.bindPopup(html, {
            offset: L.point(0, 0)
        });
    },
    updateDetails: function (train) {
        'use strict';
        this.train_id = train.id;
        this.startPoint = train.point;
        this.justLeft = train.left;
        this.title = train.title;
        this.string = train.string;
        this.link = train.link;
        this.route = train.next;
    },
    calculateLocation: function () {
        'use strict';
        var now = new Date(), secs = (starttime - map.date) / 1000 + extra + (now - starttime) / 1000, point = 0, from = this.startPoint, from_name = this.justLeft, r, stop, dlat, dlng, new_lat, new_lng;
        for (r = 0; r < this.route.length; r += 1) {
            stop = this.route[r];
            if (secs < stop.mins * 60) {
                dlat = stop.point[0] - from[0];
                dlng = stop.point[1] - from[1];
                new_lat = from[0] + dlat / (stop.mins * 60) * secs;
                new_lng = from[1] + dlng / (stop.mins * 60) * secs;
                point = [new_lat, new_lng];
                this.info = '(left ' + from_name + ',<br>expected ' + stop.name;
                if (stop.dexp) { this.info += ' ' + stop.dexp; }
                this.info += ')';
                break;
            }
            secs -= stop.mins * 60;
            from = stop.point;
            from_name = stop.name;
        }
        if (!point) { point = from; }
        this.point = point;
        this.setLatLng(this.point);
        this.createTitle();
    },
    options: {
        icon: new BaseIcon({
            iconUrl: "http://traintimes.org.uk/map/tube/i/pin_yellow.png",
            iconSize: [ 12, 20 ],
            iconAnchor: [ 6, 20 ],
            popupAnchor: [ 5, 1 ]
        })
    }
});

var Message = {
    show : function (width, marginLeft, text) {
        'use strict';
        var loading = document.getElementById('loading');
        loading.style.width = width;
        loading.style.marginLeft = marginLeft;
        loading.innerHTML = text;
        loading.style.display = 'block';
    },
    showWait : function () {
        'use strict';
        this.show('32px', '-16px', '<img src="http://traintimes.org.uk/map/tube/i/loading.gif" alt="Loading..." width="32" height="32">');
    },
    showText : function (text) {
        'use strict';
        setOpacity(document.getElementById('map'), 0.4);
        this.show('30%', '-15%', text);
    },
    hideBox : function () {
        'use strict';
        document.getElementById('loading').style.display = 'none';
    }
};

// Updates from server, site, and periodically
var Update = {
    mapStart: function () {
        'use strict';
        Update.map(true);
    },
    mapSubsequent: function () {
        'use strict';
        Update.map(false);
    },
    map: function (refresh) {
        'use strict';
        var name = 'london';
        Message.showWait();
        reqwest({
            url: 'https://strasweb.fr/opendata/API_CTS/data.php',
            type: 'json',
            error: function (err) {
                Message.showText('Data could not be fetched');
            },
            success: function (data) {
                var date = data.lastupdate, markers, lines, l, line, colour, opac, pos;
                document.getElementById('update').innerHTML = date;
                map.date = new Date(date);
                if (refresh) {
                    lines = data.polylines;
                    for (l = 0; lines && l < lines.length; l += 1) {
                        line = lines[l];
                        colour = line.shift();
                        opac = line.shift();
                        if (line.length) {
                            L.polyline(line, { color: colour, weight: 4, opacity: opac }).addTo(map);
                        }
                    }

                    markers = data.stations;
                    if (data.trains) { markers = markers.concat(data.trains); }

                } else {
                    trains.clearLayers();
                    markers = data.trains;
                }

                window.setTimeout(Update.mapSubsequent, 1000 * 60 * 2);

                for (pos = 0; markers && pos < markers.length; pos += 1) {
                    if (markers[pos].name) { // Station
                        new Station(markers[pos]).addTo(map);
                    } else if (markers[pos].title) { // Train
                        //var train_id = markers[pos].id;
                        //if (train_by_id[train_id]) {
                                                //    train = train_by_id[train_id];
                        //    train.updateDetails(markers[pos]);
                        //} else {
                        trains.addLayer(new Train(markers[pos]));
                        //    train_by_id[train_id] = train;
                        //}
                    }
                }
                Message.hideBox();
                if (refresh) { window.setTimeout(Update.trains, 1000); }
            }
        });
    },
    trains : function () {
        'use strict';
        trains.eachLayer(function (train) {
            train.calculateLocation();
        });
        window.setTimeout(Update.trains, 1000);
    }
};

function load() {
    'use strict';
    map = L.map('map').setView([48.5815, 7.74630555555556], 13);
    L.tileLayer('http://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© les contributeurs d\'<a href="http://openstreetmap.org">OpenStreetMap</a>.',
    //L.tileLayer('http://{s}.tile.stamen.com/toner/{z}/{x}/{y}.png', {
    //    attribution: 'Map data by <a href="http://openstreetmap.org">OpenStreetMap</a>. Tiles by <a href="http://stamen.com/">Stamen Design</a>.',
        minZoom: 10,
        maxZoom: 18
    }).addTo(map);
    trains.addTo(map);
    Update.mapStart();
}

var Info = {
    HiddenText : '<p id="showhide"><a href="" onclick="Info.Show(); return false;">Plus d\'informations &darr;</a></p>',
    Hide : function () {
        'use strict';
        var i = document.getElementById('info');
        this.content = i.innerHTML;
        i.innerHTML = this.HiddenText;
        i.style.width = 'auto';
    },
    Show : function () {
        'use strict';
        var i = document.getElementById('info');
        i.innerHTML = this.content;
        i.style.width = '18em';
    }
};






