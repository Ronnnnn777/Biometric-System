(() => {
  const modal = document.getElementById('manual-modal');
  const input = document.getElementById('manual-student-name');
  const options = document.getElementById('manual-options');
  if (!modal || !input || !options) return;

  const helpText = modal.querySelector('h1 + p');
  if (helpText) helpText.textContent = 'Search for a student by Student ID.';
  input.placeholder = 'Student ID';
  input.setAttribute('aria-label', 'Student ID');

  let searchTimer;
  input.oninput = () => {
    const studentId = input.value.trim();
    clearTimeout(searchTimer);

    if (studentId.length < 2) {
      options.classList.add('hidden');
      options.replaceChildren();
      return;
    }

    searchTimer = setTimeout(async () => {
      try {
        const response = await fetch('manual-students-search.php?student_id=' + encodeURIComponent(studentId));
        if (!response.ok) throw new Error('Student lookup failed.');

        const data = await response.json();
        options.replaceChildren();

        (data.students || []).forEach(student => {
          const button = document.createElement('button');
          const photo = document.createElement('img');
          const details = document.createElement('span');
          const name = document.createElement('span');
          const id = document.createElement('span');

          button.type = 'button';
          button.className = 'flex w-full items-center gap-3 rounded-lg px-3 py-3 text-left hover:bg-slate-100';
          photo.className = 'h-12 w-12 shrink-0 rounded-xl border border-slate-200 object-cover';
          photo.src = '../' + (student.IMAGE_PATH || 'image/student-preview.png');
          photo.alt = '';
          details.className = 'min-w-0';
          name.className = 'block truncate font-bold text-slate-900';
          id.className = 'mt-0.5 block text-xs font-semibold text-slate-500';
          name.textContent = student.STUDENT_NAME;
          id.textContent = student.STUDENT_ID;
          details.append(name, id);
          button.append(photo, details);

          button.onclick = () => {
            input.value = student.STUDENT_ID;
            window.selectStudent(student.STUDENT_ID);
          };
          options.appendChild(button);
        });

        options.classList.toggle('hidden', !options.children.length);
      } catch (error) {
        options.classList.add('hidden');
      }
    }, 250);
  };
})();
