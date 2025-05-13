function toCSV(rows){
  const esc=v=>`"${String(v??'').replace(/"/g,'""')}"`;
  const header='Name,Club,Event,Rating,URL';
  return [header,...rows.map(r=>[r.name,r.club,r.event,r.rating,r.url].map(esc).join(','))].join('\r\n');
}
$(function(){
  $('#copyBtn').on('click',()=>navigator.clipboard.writeText(
    window.tableData.map(r=>`${r.name} ${r.rating?'('+r.rating+')':''} — ${r.url}`).join('\n')
  ).then(()=>alert('Copied!')));
  $('#csvBtn').on('click',()=>{
    const blob=new Blob([toCSV(window.tableData)],{type:'text/csv'});
    const url=URL.createObjectURL(blob);
    $('<a>').attr({href:url,download:'fencers.csv'})[0].click();
    URL.revokeObjectURL(url);
  });
});
