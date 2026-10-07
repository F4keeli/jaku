/* =========================================================
   Placeholder-page behaviour: route title + socials
   ========================================================= */
(function (window, document) {
  'use strict';

  var SI = window.SI;

  var ROUTES = {
    games: {
      stage: 'Section 3',
      copy: 'Every game the studio is building, including titles still in production. This list is driven by the admin panel.'
    },
    team: {
      stage: 'Section 3',
      copy: 'The full roster across Leadership, Management, Staff, Development, and Testing — with ranks pulled from Discord.'
    },
    contact: {
      stage: 'Section 4',
      copy: 'Questions, bug reports, collaboration, or advice. A human verification step runs before anything is sent.'
    },
    application: {
      stage: 'Section 5',
      copy: 'Open roles with their requirements, live applicant counts, and closing dates. Applications are configured from the panel.'
    },
    store: {
      stage: 'Section 6',
      copy: 'Shop, StitchBlocks currency, and supporter roles. Every order is tied to a verified Discord ID before payment.'
    }
  };

  document.addEventListener('DOMContentLoaded', function () {
    var key = (window.location.pathname.split('/').filter(Boolean)[0] || '').toLowerCase();
    var route = ROUTES[key];
    if (!route) return;

    var title = key.charAt(0).toUpperCase() + key.slice(1);

    document.title = title + ' — Stitchline Interactive';
    var titleNode = SI.$('#stubTitle');
    if (titleNode) { titleNode.textContent = title; titleNode.setAttribute('data-glitch', title); }
    var stageNode = SI.$('#stubStage');
    if (stageNode) stageNode.textContent = route.stage + ' — Incoming';
    var copyNode = SI.$('#stubCopy');
    if (copyNode) copyNode.textContent = route.copy;

    var link = document.querySelector('.nav__link[href="/' + key + '"]');
    if (link) link.classList.add('is-active');

    SI.api.get('/api/site').then(function (data) {
      if (!data) return;
      SI.renderSocials(data.socials);
      if (data.studio && data.studio.cursor) {
        var cube = SI.$('#cursor img');
        if (cube) cube.src = data.studio.cursor;
      }
    }).catch(function () { /* placeholder page still works */ });
  });
})(window, document);
