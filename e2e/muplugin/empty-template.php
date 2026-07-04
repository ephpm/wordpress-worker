<?php
// Intentionally empty template. The ePHPm worker e2e probe already echoed its
// plain-text snapshot during 'init'; this template renders nothing so the probe
// response contains ONLY the snapshot (no theme HTML), and the request finishes
// without exit() so the persistent worker stays alive.
