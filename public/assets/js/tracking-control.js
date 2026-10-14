(() => {
    'use strict';

    const config = window.VibrantTracking;

    if (!config || !config.shipmentId) {
        console.error('VibrantTracking configuration is missing.');
        return;
    }

    const shipmentId = Number(config.shipmentId);
    let timer = null;
    let busy = false;
    let map = null;
    let shipmentMarker = null;
    let routeLine = null;
    let routePoints = [];

    const el = (id) => document.getElementById(id);

    const text = (id, value) => {
        const node = el(id);
        if (node) node.textContent = value;
    };

    const message = (value, success = true) => {
        const box = el('tracking-message');

        if (!box) {
            console.log(value);
            return;
        }

        box.textContent = value;
        box.className = success
            ? 'tracking-message success'
            : 'tracking-message error';

        box.hidden = false;

        setTimeout(() => {
            box.hidden = true;
        }, 4000);
    };

    function validCoordinate(lat, lng) {
        return Number.isFinite(lat)
            && Number.isFinite(lng)
            && lat >= -90
            && lat <= 90
            && lng >= -180
            && lng <= 180
            && !(lat === 0 && lng === 0);
    }

    function initializeMap() {
        const container = el('globalTrackingMap');

        if (!container) {
            console.error('Global tracking map container not found.');
            return;
        }

        if (typeof L === 'undefined') {
            container.textContent = 'Map library failed to load.';
            console.error('Leaflet is not available.');
            return;
        }

        map = L.map(container, {
            worldCopyJump: true,
            zoomControl: true,
            scrollWheelZoom: true
        });

        L.tileLayer(
            'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
            {
                maxZoom: 19,
                attribution: '&copy; OpenStreetMap contributors'
            }
        ).addTo(map);

        buildRoute();

        const currentLat = Number(config.current?.lat);
        const currentLng = Number(config.current?.lng);

        if (validCoordinate(currentLat, currentLng)) {
            shipmentMarker = L.marker(
                [currentLat, currentLng],
                {
                    title: 'Current Shipment Location'
                }
            ).addTo(map);

            shipmentMarker.bindPopup(
                '<strong>Current Shipment Location</strong>'
            );
        }

        fitMapToRoute();

        setTimeout(() => {
            if (map) {
                map.invalidateSize();
            }
        }, 300);
    }

    function buildRoute() {
        if (!map) return;

        routePoints = [];

        const originLat = Number(config.origin?.lat);
        const originLng = Number(config.origin?.lng);

        if (validCoordinate(originLat, originLng)) {
            routePoints.push([originLat, originLng]);

            L.marker([originLat, originLng])
                .addTo(map)
                .bindPopup(
                    `<strong>Origin</strong><br>${escapeHtml(
                        config.origin?.name || 'Shipment Origin'
                    )}`
                );
        }

        const waypoints = Array.isArray(config.waypoints)
            ? config.waypoints
            : [];

        waypoints.forEach((waypoint) => {
            const lat = Number(waypoint.latitude);
            const lng = Number(waypoint.longitude);

            if (!validCoordinate(lat, lng)) return;

            routePoints.push([lat, lng]);

            L.circleMarker([lat, lng], {
                radius: 7
            })
                .addTo(map)
                .bindPopup(
                    `<strong>${escapeHtml(
                        waypoint.name || 'Waypoint'
                    )}</strong><br>${escapeHtml(
                        waypoint.address || ''
                    )}`
                );
        });

        const destinationLat = Number(config.destination?.lat);
        const destinationLng = Number(config.destination?.lng);

        if (validCoordinate(destinationLat, destinationLng)) {
            routePoints.push([destinationLat, destinationLng]);

            L.marker([destinationLat, destinationLng])
                .addTo(map)
                .bindPopup(
                    `<strong>Destination</strong><br>${escapeHtml(
                        config.destination?.name || 'Shipment Destination'
                    )}`
                );
        }

        if (routePoints.length >= 2) {
            routeLine = L.polyline(routePoints, {
                weight: 4,
                opacity: 0.75
            }).addTo(map);
        }
    }

    function fitMapToRoute() {
        if (!map) return;

        const boundsPoints = [...routePoints];

        const currentLat = Number(config.current?.lat);
        const currentLng = Number(config.current?.lng);

        if (validCoordinate(currentLat, currentLng)) {
            boundsPoints.push([currentLat, currentLng]);
        }

        if (boundsPoints.length === 0) {
            map.setView([20, 0], 2);
            return;
        }

        if (boundsPoints.length === 1) {
            map.setView(boundsPoints[0], 8);
            return;
        }

        map.fitBounds(boundsPoints, {
            padding: [40, 40],
            maxZoom: 10
        });
    }

    function updateMapPosition(data) {
        if (!map) return;

        const lat = Number(data.latitude);
        const lng = Number(data.longitude);

        if (!validCoordinate(lat, lng)) return;

        if (!shipmentMarker) {
            shipmentMarker = L.marker([lat, lng], {
                title: 'Current Shipment Location'
            }).addTo(map);

            shipmentMarker.bindPopup(
                '<strong>Current Shipment Location</strong>'
            );
        } else {
            shipmentMarker.setLatLng([lat, lng]);
        }

        shipmentMarker.setPopupContent(
            `<strong>Shipment ${escapeHtml(
                data.tracking_number || ''
            )}</strong><br>` +
            `Status: ${escapeHtml(formatStatus(data.status))}<br>` +
            `Speed: ${Number(data.speed).toFixed(1)} km/h`
        );
    }

    function escapeHtml(value) {
        return String(value ?? '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }

    async function action(name, extra = {}) {
        const body = new URLSearchParams();

        body.set('shipment_id', String(shipmentId));
        body.set('action', name);

        Object.entries(extra).forEach(([key, value]) => {
            body.set(key, String(value));
        });

        const response = await fetch(
            'tracking-action.php',
            {
                method: 'POST',
                headers: {
                    'Content-Type':
                        'application/x-www-form-urlencoded;charset=UTF-8',
                    'Accept': 'application/json'
                },
                body
            }
        );

        const result = await response.json();

        if (!response.ok || !result.success) {
            throw new Error(
                result.message || 'Tracking action failed.'
            );
        }

        return result;
    }

    async function tick() {
        if (busy) return;

        busy = true;

        try {
            const response = await fetch(
                `tracking-tick.php?id=${encodeURIComponent(
                    shipmentId
                )}&_=${Date.now()}`,
                {
                    headers: {
                        'Accept': 'application/json'
                    },
                    cache: 'no-store'
                }
            );

            const result = await response.json();

            if (!response.ok || !result.success) {
                throw new Error(
                    result.message || 'Tracking update failed.'
                );
            }

            update(result.data);
        } catch (error) {
            console.error(error);
        } finally {
            busy = false;
        }
    }

    function update(data) {
        text(
            'current-latitude',
            Number(data.latitude).toFixed(6)
        );

        text(
            'current-longitude',
            Number(data.longitude).toFixed(6)
        );

        text(
            'current-speed',
            `${Number(data.speed).toFixed(1)} km/h`
        );

        text(
            'distance-total',
            `${Number(data.distance_total).toFixed(2)} km`
        );

        text(
            'distance-travelled',
            `${Number(data.distance_travelled).toFixed(2)} km`
        );

        text(
            'distance-remaining',
            `${Number(data.distance_remaining).toFixed(2)} km`
        );

        text(
            'current-status',
            formatStatus(data.status)
        );

        text(
            'slipment-eta',
            data.eta || 'Calculating...'
        );

        text(
            'trackingStatus',
            formatStatus(data.status)
        );

        text(
            'trackingSpeed',
            `${Number(data.speed).toFixed(1)} km/h`
        );

        text(
            'trackingTravelled',
            `${Number(data.distance_travelled).toFixed(2)} km`
        );

        text(
            'trackingRemaining',
            `${Number(data.distance_remaining).toFixed(2)} km`
        );

        text(
            'trackingEta',
            data.eta || 'Calculating...'
        );

        text(
            'trackingMode',
            formatStatus(data.tracking_mode)
        );

        updateMapPosition(data);

        if (
            data.status === 'delivered'
            || Number(data.is_running) !== 1
        ) {
            stopPolling();
        } else {
            startPolling();
        }

        if (typeof config.onPositionUpdate === 'function') {
            config.onPositionUpdate(data);
        }
    }

    function formatStatus(value) {
        if (!value) return 'Unknown';

        return String(value)
            .replaceAll('_', ' ')
            .replace(/\b\w/g, (c) => c.toUpperCase());
    }

    function startPolling() {
        if (timer !== null) return;

        timer = setInterval(tick, 1000);
    }

    function stopPolling() {
        if (timer !== null) {
            clearInterval(timer);
            timer = null;
        }
    }

    async function start() {
        try {
            const speed = Number(
                el('control-speed')?.value || 0
            );

            if (speed <= 0) {
                message(
                    'Set a speed greater than zero first.',
                    false
                );
                return;
            }

            await action('speed', { speed });
            await action('start');

            message('Shipment tracking started.');

            await tick();
            startPolling();
        } catch (error) {
            message(error.message, false);
        }
    }

    async function pause() {
        try {
            const reason =
                el('pause-reason')?.value.trim()
                || 'Admin paused tracking';

            await action('pause', { reason });

            message('Shipment tracking paused.');

            await tick();
        } catch (error) {
            message(error.message, false);
        }
    }

    async function resume() {
        try {
            const speed = Number(
                el('control-speed')?.value || 0
            );

            if (speed <= 0) {
                message(
                    'Set a speed greater than zero first.',
                    false
                );
                return;
            }

            await action('speed', { speed });
            await action('resume');

            message('Shipment tracking resumed.');

            await tick();
            startPolling();
        } catch (error) {
            message(error.message, false);
        }
    }

    async function updateSpeed() {
        try {
            const speed = Number(
                el('control-speed')?.value || 0
            );

            if (speed < 0) {
                message(
                    'Speed cannot be negative.',
                    false
                );
                return;
            }

            await action('speed', { speed });

            message('Tracking speed updated.');

            await tick();
        } catch (error) {
            message(error.message, false);
        }
    }

    async function updateMode() {
        try {
            const mode = el('tracking-mode')?.value;

            if (!mode) return;

            await action('mode', { mode });

            message('Tracking mode updated.');

            await tick();
        } catch (error) {
            message(error.message, false);
        }
    }

    async function savePosition() {
        try {
            const latitude = Number(
                el('current-latitude-input')?.value
            );

            const longitude = Number(
                el('current-longitude-input')?.value
            );

            const speed = Number(
                el('control-speed')?.value || 0
            );

            const mode =
                el('tracking-mode')?.value || 'simulation';

            if (
                !validCoordinate(latitude, longitude)
            ) {
                message(
                    'Enter valid latitude and longitude.',
                    false
                );
                return;
            }

            await action('save_position', {
                latitude,
                longitude,
                speed,
                mode
            });

            message('Position and speed saved.');

            await tick();

            if (map) {
                map.setView(
                    [latitude, longitude],
                    Math.max(map.getZoom(), 5)
                );
            }
        } catch (error) {
            message(error.message, false);
        }
    }

    async function recordEvent() {
        try {
            const type =
                el('event-type')?.value || '';

            const location =
                el('event-location')?.value.trim() || '';

            const description =
                el('event-description')?.value.trim() || '';

            if (!type) {
                message(
                    'Select an event type.',
                    false
                );
                return;
            }

            if (!description) {
                message(
                    'Enter the event description.',
                    false
                );
                return;
            }

            await action('event', {
                event_type: type,
                location,
                description
            });

            message('Operational event recorded.');

            if (el('event-description')) {
                el('event-description').value = '';
            }
        } catch (error) {
            message(error.message, false);
        }
    }

    window.controlAction = (name) => {
        if (name === 'start') start();
        if (name === 'pause') pause();
        if (name === 'resume') resume();
    };

    window.savePosition = savePosition;
    window.recordEvent = recordEvent;

    document.addEventListener('DOMContentLoaded', () => {
        initializeMap();

        tick();
    });
})();
