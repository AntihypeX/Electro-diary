'use strict';

const gradeMatrix = document.querySelector('#teacher-matrix');
const gradePicker = document.createElement('div');
gradePicker.className = 'quick-grade-picker';
gradePicker.hidden = true;
gradePicker.setAttribute('role','dialog');
gradePicker.setAttribute('aria-label','Выставить отметку');
// Position remains anchored even if an old stylesheet was cached.
Object.assign(gradePicker.style,{position:'fixed',zIndex:'80',width:'280px',maxWidth:'calc(100vw - 16px)'});
document.body.append(gradePicker);
let gradeSaving=false;
let gradeAnchor;

function closeGradePicker() {
  if(gradeSaving)return;
  gradePicker.hidden=true;
  gradeAnchor?.focus({preventScroll:true});
}
document.addEventListener('click',event=>{
  const cell=event.target.closest('.teacher-cell');
  const button=event.target.closest('[data-final],[data-action]') ||
    cell?.querySelector('[data-action="grade_add"],[data-action="attestation"],[data-action="grade_edit"]');
  if(!button)return;
  const final=button.hasAttribute('data-final');
  const action=final?'attestation':button.dataset.action;
  if(!['attestation','grade_add','grade_edit'].includes(action))return;
  event.preventDefault();event.stopImmediatePropagation();
  if(gradeSaving)return;
  gradeAnchor=button;
  const row=button.closest('[data-student]');
  const data=final?{...JSON.parse(button.dataset.final),action}:{action,student:row.dataset.student};
  if(!final&&action==='attestation'){
    Object.assign(data,{kind:button.dataset.kind,period:button.dataset.period,group:button.dataset.group,
      subject:button.dataset.subject,previous:button.dataset.value||null});
  }else if(!final){
    Object.assign(data,{lesson:cell.dataset.lesson,expectedCount:Number(cell.dataset.count)});
    if(action==='grade_edit')Object.assign(data,{entry:button.dataset.entry,previous:button.dataset.value});
  }
  let batch=false;
  const queued=[];
  const caption=document.createElement('p');
  caption.textContent=final?'Итоговая отметка':row.dataset.name;
  const error=document.createElement('p');error.setAttribute('role','alert');
  const choices=document.createElement('div');choices.className='quick-grade-options';
  const extra=document.createElement('button');extra.type='button';extra.className='cab-button';extra.textContent='Несколько оценок за занятие';
  const selected=document.createElement('div');selected.className='quick-grade-queued';
  const save=document.createElement('button');save.type='button';save.className='primary';save.textContent='Сохранить оценки';
  function position(){
    const box=button.getBoundingClientRect();
    gradePicker.style.left=Math.max(8,Math.min(innerWidth-gradePicker.offsetWidth-8,box.left))+'px';
    gradePicker.style.top=Math.max(8,box.top-gradePicker.offsetHeight-8>=8?box.top-gradePicker.offsetHeight-8:Math.min(innerHeight-gradePicker.offsetHeight-8,box.bottom+8))+'px';
  }
  async function submit(values){
    if(gradeSaving)return;
    gradeSaving=true;
    gradePicker.querySelectorAll('button').forEach(b=>b.disabled=true);
    const payload={...data};
    if(action==='grade_add')payload.grades=values;else payload.grade=values[0];
    try{
      let options;
      if(final){
        const account=await fetch('account.php').then(r=>r.json());
        const body=new FormData();body.append('csrf',account.csrf);body.append('payload',JSON.stringify(payload));
        options={method:'POST',headers:{Accept:'application/json'},body};
      }else options={method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':document.querySelector('meta[name="csrf-token"]').content},body:JSON.stringify(payload)};
      const response=await fetch(location.href,options);
      const result=await response.json();
      if(!response.ok||!result.ok)throw new Error(result.error||'Не удалось сохранить.');
      if(gradeMatrix)history.replaceState({...history.state,teacherMatrixScroll:gradeMatrix.scrollLeft},'');
      location.reload();
    }catch(e){gradeSaving=false;error.textContent=e.message;render();}
  }
  function render(){
    gradePicker.replaceChildren(caption,choices);
    choices.replaceChildren();
    const variants=action==='attestation'?(data.kind==='credit'?['зачтено','не зачтено','н/а']:['2','3','4','5','н/а']):['2','3','4','5','н'];
    for(const value of variants){
      const choice=document.createElement('button');choice.type='button';choice.textContent=value;
      choice.setAttribute('aria-label','Поставить '+value);
      choice.disabled=value==='н'&&((data.expectedCount>0&&action==='grade_add')||(data.expectedCount>1&&action==='grade_edit')||queued.length>0);
      if(batch&&(queued.includes('н')||queued.length>=10))choice.disabled=true;
      choice.onclick=()=>{if(batch){queued.push(value);render();}else submit([value]);};
      choices.append(choice);
    }
    if(action==='grade_add'){
      if(!batch)gradePicker.append(extra);
      else{
        selected.replaceChildren();
        queued.forEach((value,index)=>{
          const chip=document.createElement('button');chip.type='button';chip.className='cab-button';chip.textContent=value+' ×';
          chip.onclick=()=>{queued.splice(index,1);render();};selected.append(chip);
        });
        save.disabled=queued.length===0;gradePicker.append(selected,save);
      }
    }
    gradePicker.append(error);position();
  }
  extra.onclick=()=>{batch=true;render();};
  save.onclick=()=>submit(queued);
  gradePicker.hidden=false;
  gradePicker.replaceChildren(caption);
  if(data.previous){
    const confirm=document.createElement('button');confirm.type='button';confirm.className='cab-button';
    confirm.textContent='Изменить отметку «'+data.previous+'»';confirm.onclick=render;gradePicker.append(confirm);position();
  }else render();
  gradePicker.querySelector('button:not(:disabled)')?.focus({preventScroll:true});
},true);
document.addEventListener('pointerdown',event=>{if(!gradePicker.contains(event.target)&&!event.target.closest('[data-action],[data-final]'))closeGradePicker();});
document.addEventListener('keydown',event=>{if(event.key==='Escape')closeGradePicker();});
window.addEventListener('resize',closeGradePicker);
