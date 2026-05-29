(function () {
  var el = document.getElementById('famtree');
  if (!el) return;
  var mainId = el.getAttribute('data-main') || null;
  var panel  = document.getElementById('famtree-current');

  function escapeHtml(s){return String(s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];});}
  function nameOf(data, id){ for (var i=0;i<data.length;i++) if (data[i].id===id) return data[i].data.name; return ''; }
  function setPanel(id, name){
    if (!panel) return;
    panel.innerHTML = 'Gecentreerd op <strong>' + escapeHtml(name||'') + '</strong> · ' +
      '<a href="persoon.php?id=' + encodeURIComponent(id) + '">Open profiel →</a>';
  }

  fetch('boom_data.php', { credentials: 'same-origin' })
    .then(function (r){ if(!r.ok) throw new Error('HTTP '+r.status); return r.json(); })
    .then(function (data){ if(!Array.isArray(data)||!data.length) throw new Error('empty'); render(data, mainId); })
    .catch(function (){
      el.innerHTML = '<p style="padding:24px">Kon de stamboom niet laden. ' +
        'Bekijk de <a href="lijst.php">Personen-lijst</a>.</p>';
    });

  function render(data, mainId){
    var chart = f3.createChart('#famtree', data)
      .setTransitionTime(800)
      .setCardXSpacing(240)
      .setCardYSpacing(140)
      .setSingleParentEmptyCard(true, { label: 'Onbekend' });

    var card = chart.setCard(f3.CardHtml)
      .setCardDisplay([["name"], ["dates"]])
      .setCardDim({ w: 220, h: 64 })
      .setMiniTree(true)
      .setOnHoverPathToMain();

    card.setOnCardClick(function (e, d){
      var id = d.data.id;
      chart.updateMainId(id);
      chart.updateTree({});
      setPanel(id, (d.data.data && d.data.data.name) ? d.data.data.name : nameOf(data, id));
    });

    if (mainId) chart.updateMainId(mainId);
    chart.updateTree({ initial: true });
    if (mainId) setPanel(mainId, nameOf(data, mainId));
  }
})();
